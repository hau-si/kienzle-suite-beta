<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Zugang zur Einstellungsseite. Ersteinrichtung über einen einmaligen Link, den der Installer
 * ausgibt; danach Admin-Passwort. Arbeitsplätze kassieren weiterhin ohne Passwort.
 */
final class Admin {
    public const COOKIE = 'ks_admin';
    private const TTL = 1800;

    public function __construct(private Database $db, private string $stateDir) {}

    public function tokenPath(): string { return rtrim($this->stateDir, '/') . '/admin-setup-token'; }
    public function configured(): bool { return $this->setting('admin_password_hash') !== null; }

    /** Für den Installer: neuen Einrichtungslink erzeugen (bei --admin-reset auch das Passwort löschen). */
    public function createSetupToken(bool $reset = false): ?string {
        if ($reset) {
            $this->db->query("DELETE FROM settings WHERE key IN ('admin_password_hash','admin_failures')");
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
        $stored = is_file($this->tokenPath()) ? trim((string)file_get_contents($this->tokenPath())) : '';
        if ($stored === '' || !hash_equals($stored, hash('sha256', $token))) {
            $this->failed();
            throw new Problem('Einrichtungslink ungültig. Neuen Link erzeugen: sudo bash scripts/install-server.sh --admin-reset', 403);
        }
        $this->setPassword($password);
        @unlink($this->tokenPath());
        return $this->open();
    }

    public function login(string $password): array {
        $this->checkLock();
        $hash = $this->setting('admin_password_hash');
        if ($hash === null) throw new Problem('Noch kein Admin-Passwort festgelegt. Bitte den Einrichtungslink aus dem Installer öffnen.', 409);
        if (!password_verify($password, $hash)) { $this->failed(); usleep(800000); throw new Problem('Passwort falsch.', 401); }
        $this->db->query("DELETE FROM settings WHERE key='admin_failures'");
        if (password_needs_rehash($hash, self::algorithm())) $this->setPassword($password);
        return $this->open();
    }

    public function changePassword(string $current, string $new): void {
        if (!password_verify($current, (string)$this->setting('admin_password_hash'))) { $this->failed(); throw new Problem('Bisheriges Passwort falsch.', 401); }
        $this->setPassword($new);
    }

    /** Liefert die Sitzung zu einem Cookie-Wert oder null; verlängert sie gleitend. */
    public function session(?string $token): ?array {
        if (!$token || !preg_match('/^[A-Za-z0-9_-]{40,64}$/D', $token)) return null;
        $row = $this->db->one('SELECT * FROM admin_sessions WHERE token_hash=? AND expires_at>?', [hash('sha256', $token), time()]);
        if (!$row) return null;
        $this->db->query('UPDATE admin_sessions SET expires_at=? WHERE token_hash=?', [time() + self::TTL, $row['token_hash']]);
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
        $this->store('admin_password_hash', password_hash($password, self::algorithm()));
        $this->db->query('DELETE FROM admin_sessions');   // andere Sitzungen abmelden
    }
    private static function algorithm(): string|int { return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT; }
    private function checkLock(): void {
        $state = json_decode((string)$this->setting('admin_failures'), true) ?: ['count' => 0, 'until' => 0];
        if ($state['until'] > time()) throw new Problem('Zu viele Fehlversuche. Bitte ' . max(1, (int)ceil(($state['until'] - time()) / 60)) . ' Minuten warten.', 429);
    }
    private function failed(): void {
        $state = json_decode((string)$this->setting('admin_failures'), true) ?: ['count' => 0, 'until' => 0];
        $state['count']++;
        // Ab dem 5. Fehlversuch 5 Minuten Sperre (gilt für die ganze Einstellungsseite).
        if ($state['count'] >= 5) $state = ['count' => 0, 'until' => time() + 300];
        $this->store('admin_failures', json_encode($state));
    }
    private function setting(string $key): ?string {
        $row = $this->db->one('SELECT value FROM settings WHERE key=?', [$key]);
        return $row ? $row['value'] : null;
    }
    private function store(string $key, string $value): void {
        $this->db->query('INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at', [$key, $value, time()]);
    }
}
