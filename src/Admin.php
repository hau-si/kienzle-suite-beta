<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Zugang zur Einstellungsseite. Ersteinrichtung über einen einmaligen Link, den der Installer
 * ausgibt; danach Admin-Passwort. Arbeitsplätze kassieren weiterhin ohne Passwort.
 */
final class Admin {
    public const COOKIE = 'ks_admin';
    private const TTL = 1800;          // Leerlauf: nach 30 Minuten ohne Aktion abgemeldet
    private const MAX_AGE = 43200;     // Höchstdauer einer Sitzung: 12 Stunden, auch bei ständiger Nutzung
    private const GLOBAL_LIMIT = 200;  // Fehlversuche aller Absender je Stunde, bevor Anmeldungen kurz pausieren

    /** Wartezeit nach falschem Passwort in Mikrosekunden (bremst Raten; Tests setzen 0). */
    public static int $failDelay = 800000;
    public readonly Throttle $throttle;
    public readonly AuditLog $audit;

    public function __construct(private Database $db, private string $stateDir, ?string $client = null) {
        $this->throttle = new Throttle($db, $client ?? '');
        $this->audit = new AuditLog($stateDir);
    }

    public function tokenPath(): string { return rtrim($this->stateDir, '/') . '/admin-setup-token'; }
    public function configured(): bool { return $this->setting('admin_password_hash') !== null; }

    /** Für den Installer: neuen Einrichtungslink erzeugen (bei --admin-reset auch das Passwort löschen). */
    public function createSetupToken(bool $reset = false): ?string {
        if ($reset) {
            $this->db->query("DELETE FROM settings WHERE key IN ('admin_password_hash','admin_failures','admin_global_failures','admin_global_lock')");
            Throttle::clearAll($this->db);
            $this->db->query('DELETE FROM admin_sessions');
        }
        if ($this->configured()) return null;
        $token = bin2hex(random_bytes(24));
        $path = $this->tokenPath();
        if (file_put_contents($path . '.new', hash('sha256', $token)) === false || !chmod($path . '.new', 0600) || !rename($path . '.new', $path)) {
            throw new Problem('Einrichtungslink konnte nicht gespeichert werden.', 500);
        }
        return $token;
    }

    public function setup(string $token, string $password): array {
        if ($this->configured()) throw new Problem('Das Admin-Passwort ist bereits festgelegt. Bitte anmelden.', 409);
        $this->checkLock();
        $stored = is_file($this->tokenPath()) ? trim((string)file_get_contents($this->tokenPath())) : '';
        if ($stored === '' || !hash_equals($stored, hash('sha256', $token))) {
            $this->failed('setup');
            throw new Problem('Einrichtungslink ungültig. Neuen Link erzeugen: sudo bash scripts/install-server.sh --admin-reset', 403);
        }
        $this->setPassword($password);
        @unlink($this->tokenPath());
        $this->audit->write('setup-ok');
        return $this->open();
    }

    public function login(string $password): array {
        $this->checkLock();
        $hash = $this->setting('admin_password_hash');
        if ($hash === null) throw new Problem('Noch kein Admin-Passwort festgelegt. Bitte den Einrichtungslink aus dem Installer öffnen.', 409);
        if (!password_verify($password, $hash)) { $this->failed('login'); usleep(self::$failDelay); throw new Problem('Passwort falsch.', 401); }
        $this->throttle->clear('login');
        $this->audit->write('login-ok');
        if (password_needs_rehash($hash, self::algorithm())) $this->setPassword($password);
        return $this->open();
    }

    public function changePassword(string $current, string $new): void {
        $this->checkLock();
        if (!password_verify($current, (string)$this->setting('admin_password_hash'))) { $this->failed('login'); usleep(self::$failDelay); throw new Problem('Bisheriges Passwort falsch.', 401); }
        $this->setPassword($new);
        $this->audit->write('passwort-geaendert');
    }

    /** Liefert die Sitzung zu einem Cookie-Wert oder null; verlängert sie gleitend. */
    public function session(?string $token): ?array {
        if (!$token || !preg_match('/^[A-Za-z0-9_-]{40,64}$/D', $token)) return null;
        $row = $this->db->one('SELECT * FROM admin_sessions WHERE token_hash=? AND expires_at>?', [hash('sha256', $token), time()]);
        if (!$row) return null;
        if ((int)$row['created_at'] < time() - self::MAX_AGE) {   // zu alt: auch bei Dauernutzung neu anmelden
            $this->db->query('DELETE FROM admin_sessions WHERE token_hash=?', [$row['token_hash']]);
            return null;
        }
        $this->db->query('UPDATE admin_sessions SET expires_at=? WHERE token_hash=?', [min(time() + self::TTL, (int)$row['created_at'] + self::MAX_AGE), $row['token_hash']]);
        return $row;
    }
    public function logout(?string $token): void {
        if ($token) $this->db->query('DELETE FROM admin_sessions WHERE token_hash=?', [hash('sha256', $token)]);
    }
    public static function cookie(string $value, int $ttl = self::TTL): string {
        return self::COOKIE . '=' . $value . '; Path=/; Secure; HttpOnly; SameSite=Strict; Max-Age=' . $ttl;
    }

    private function open(): array {
        $this->db->query('DELETE FROM admin_sessions WHERE expires_at<?', [time()]);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $csrf = bin2hex(random_bytes(24));
        $this->db->query('INSERT INTO admin_sessions(token_hash,csrf,created_at,expires_at) VALUES(?,?,?,?)', [hash('sha256', $token), $csrf, time(), time() + self::TTL]);
        return ['token' => $token, 'csrf' => $csrf];
    }
    private function setPassword(string $password): void {
        if (mb_strlen($password) < 10) throw new Problem('Das Admin-Passwort muss mindestens 10 Zeichen lang sein.', 422);
        if (mb_strlen($password) > 200) throw new Problem('Das Admin-Passwort ist zu lang.', 422);
        if (self::weak($password)) throw new Problem('Dieses Passwort ist zu einfach zu erraten. Bitte mehrere Wörter oder eine längere Zeichenfolge wählen, die nicht „passwort“, „admin“ oder eine Zahlenreihe enthält.', 422);
        $this->store('admin_password_hash', password_hash($password, self::algorithm()));
        $this->db->query('DELETE FROM admin_sessions');   // andere Sitzungen abmelden
    }
    private static function algorithm(): string|int { return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT; }
    /** Häufige und offensichtliche Passwörter ablehnen (Länge prüft der Aufrufer). */
    public static function weak(string $password): bool {
        $plain = mb_strtolower($password);
        if (count(array_unique(mb_str_split($plain))) < 5) return true;           // z. B. „aaaaaaaaaaaa“
        $squeezed = preg_replace('/[^a-z0-9]/', '', strtr($plain, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 's']));
        $rest = $squeezed;
        foreach (['passwort', 'password', 'admin', 'kienzle', 'sumup', 'qwertz', 'qwert', 'asdf', 'letmein', 'welcome', 'willkommen', 'praxis', 't2med',
                  '1234567', '0123456', 'abcdefg', '1111111', '0000000'] as $bad) $rest = str_replace($bad, '', $rest);
        // Besteht das Passwort fast nur aus solchen Mustern (plus ein paar Ziffern), ist es zu schwach.
        if ($rest !== $squeezed && strlen($rest) < 6) return true;
        return false;
    }
    private function checkLock(): void {
        $this->throttle->check('login');
        $until = (int)(json_decode((string)$this->setting('admin_global_lock'), true)['until'] ?? 0);
        if ($until > time()) throw new Problem('Zu viele Anmeldeversuche. Bitte ' . max(1, (int)ceil(($until - time()) / 60)) . ' Minuten warten.', 429);
    }
    private function failed(string $scope): void {
        // Je Absender: 5 Fehlversuche → 5 Minuten Sperre, bei Wiederholung doppelt so lang (höchstens 1 Stunde).
        $this->throttle->fail($scope === 'setup' ? 'login' : $scope);
        $this->audit->write($scope . '-fehlgeschlagen');
        // Gesamtbremse gegen verteilte Angriffe aus vielen Adressen: nur kurze Pause für alle.
        $state = json_decode((string)$this->setting('admin_global_failures'), true) ?: ['count' => 0, 'since' => time()];
        if ($state['since'] < time() - 3600) $state = ['count' => 0, 'since' => time()];
        if (++$state['count'] >= self::GLOBAL_LIMIT) {
            $this->store('admin_global_lock', json_encode(['until' => time() + 900]));
            $state = ['count' => 0, 'since' => time()];
        }
        $this->store('admin_global_failures', json_encode($state));
    }
    private function setting(string $key): ?string {
        $row = $this->db->one('SELECT value FROM settings WHERE key=?', [$key]);
        return $row ? $row['value'] : null;
    }
    private function store(string $key, string $value): void {
        $this->db->query('INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at', [$key, $value, time()]);
    }
}
