<?php
declare(strict_types=1);
// Einstellungen im Browser: verschlüsselte Überlagerung der Konfigurationsdatei, Admin-Zugang.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{Admin, Config, Database, Problem, Settings, SettingsPage};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function problem(callable $fn, string $message, int $http): Problem {
    try { $fn(); } catch (Problem $e) { check($e->http === $http, $message . ' (HTTP ' . $e->http . ': ' . $e->getMessage() . ')'); return $e; }
    throw new RuntimeException($message);
}
function base(): Config {
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = sys_get_temp_dir() . '/ks-settings-' . bin2hex(random_bytes(6));
    mkdir($v['app']['state_dir'], 0700);
    $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    return new Config($v);
}

$dirs = [];
try {
    $base = base(); $dirs[] = $state = $base->get('app', 'state_dir');
    $db = new Database($state); $db->migrate();

    // Ohne gespeicherte Werte gilt die Datei unverändert.
    check(Settings::effective($base) === $base, 'Ohne Überlagerung nicht die Basiskonfiguration');

    // Praxisdaten speichern: wirksam, verschlüsselt abgelegt, Basis unberührt.
    Settings::save($db, $base, 'practice', ['name' => 'Beispielpraxis', 'street' => 'Musterweg 1', 'city' => '12345 Musterstadt', 'contact' => '', 'append_sumup_receipt' => false]);
    $eff = Settings::effective($base);
    check($eff->get('practice', 'name') === 'Beispielpraxis' && $eff->practiceReady(), 'Praxisdaten nicht wirksam');
    check($base->get('practice', 'name', '') !== 'Beispielpraxis', 'Basiskonfiguration verändert');
    $raw = (string)(new PDO('sqlite:' . $state . '/payments.sqlite'))->query("SELECT value FROM settings WHERE key='config_overrides'")->fetchColumn();
    check($raw !== '' && !str_contains($raw, 'Beispielpraxis'), 'Einstellungen unverschlüsselt gespeichert');

    // SumUp live: Geheimnis wird nie angezeigt, leeres Feld behält den Wert.
    $sumup = ['mode' => 'live', 'api_key' => 'sup-geheim', 'affiliate_key' => 'aff-geheim', 'app_id' => 'test.app', 'merchant_code' => 'MTEST', 'reader_id' => 'rdr'];
    Settings::save($db, $base, 'app', ['max_amount_cents' => '250']);
    problem(fn() => Settings::save($db, $base, 'sumup', ['mode' => 'mock'] + $sumup) && Settings::save($db, $base, 'fhir', ['mode' => 'live', 'base_url' => 'https://t2med.test/aps/fhir/api/r4', 'launch_urls' => '', 'api_key' => '', 'entry_code' => 'ZAHLUNG']), 'SumUp-Testmodus mit T2med live akzeptiert', 422);
    Settings::save($db, $base, 'sumup', $sumup);
    $eff = Settings::effective($base);
    check($eff->get('app', 'max_amount_cents') === 25000, 'Höchstbetrag nicht in Cent umgerechnet');
    $json = json_encode(Settings::view($eff));
    check(!str_contains($json, 'sup-geheim') && !str_contains($json, 'aff-geheim'), 'Geheimnis in der Ansicht');
    Settings::save($db, $base, 'sumup', ['api_key' => ''] + $sumup);
    check(Settings::effective($base)->get('sumup', 'api_key') === 'sup-geheim', 'Leeres Geheimnisfeld hat den Schlüssel gelöscht');

    // T2med live ohne Key: Demo-Key und erlaubte Adresse automatisch.
    Settings::save($db, $base, 'fhir', ['mode' => 'live', 'base_url' => 'https://t2med.test/aps/fhir/api/r4/', 'launch_urls' => '', 'api_key' => '', 'entry_code' => 'ZAHLUNG']);
    $eff = Settings::effective($base);
    check($eff->get('fhir', 'api_key') === Settings::T2MED_DEMO_API_KEY, 'Demo-Key nicht gesetzt');
    check($eff->get('fhir', 'launch_urls') === ['https://t2med.test/aps/fhir/api/r4'], 'Launch-Adresse nicht abgeleitet');

    // Ungültige Eingaben: Klartext-Fehler, nichts gespeichert.
    problem(fn() => Settings::save($db, $base, 'app', ['max_amount_cents' => 'viel']), 'Ungültiger Betrag akzeptiert', 422);
    problem(fn() => Settings::save($db, $base, 'printing', ['enabled' => true, 'model' => 'gibtsnicht']), 'Unbekanntes Druckermodell akzeptiert', 422);
    problem(fn() => Settings::save($db, $base, 'unbekannt', []), 'Unbekannter Bereich akzeptiert', 404);
    check(Settings::effective($base)->get('app', 'max_amount_cents') === 25000, 'Fehlerhafte Eingabe teilweise gespeichert');

    // Bondrucker TM-m30III: Breite nach Modell; Rechnungseingang erzeugt den Schlüssel selbst.
    Settings::save($db, $base, 'printing', ['enabled' => true, 'model' => 'tm-m30iii', 'transport' => 'cups', 'queue' => 'TM-m30III']);
    check(Settings::effective($base)->get('printing', 'width_dots') === Config::PRINTER_MODELS['tm-m30iii']['width_dots'], 'Breite nicht nach Modell');
    Settings::save($db, $base, 'invoice_inbox', ['enabled' => true]);
    check((bool)preg_match('/^[a-f0-9]{64}$/D', (string)Settings::effective($base)->get('invoice_inbox', 'key')), 'Eingangsschlüssel nicht erzeugt');

    // Admin: Einrichtungslink, Anmeldung, CSRF, Sperre, Zurücksetzen.
    $admin = new Admin($db, $state);
    $token = $admin->createSetupToken();
    check(is_string($token) && (fileperms($admin->tokenPath()) & 0777) === 0600, 'Einrichtungslink nicht geschützt gespeichert');
    check(!str_contains((string)file_get_contents($admin->tokenPath()), $token), 'Einrichtungslink im Klartext gespeichert');
    $page = new SettingsPage($base);
    check($page->handle('status', [], null, null)['setup_required'] === true, 'Einrichtung nicht verlangt');
    problem(fn() => $page->handle('get', [], null, null), 'Einstellungen ohne Anmeldung lesbar', 401);
    problem(fn() => $page->handle('setup', ['token' => 'falsch', 'password' => 'ein-langes-passwort'], null, null), 'Falscher Einrichtungslink akzeptiert', 403);
    problem(fn() => $page->handle('setup', ['token' => $token, 'password' => 'kurz'], null, null), 'Zu kurzes Passwort akzeptiert', 422);
    $login = $page->handle('setup', ['token' => $token, 'password' => 'ein-langes-passwort'], null, null);
    check(preg_match('/^ks_admin=([A-Za-z0-9_-]+);.*Secure; HttpOnly; SameSite=Strict/', (string)$page->cookie(), $m) === 1, 'Sitzungscookie unsicher');
    $session = $m[1];
    check(!is_file($admin->tokenPath()), 'Einrichtungslink nach Gebrauch noch gültig');
    problem(fn() => $page->handle('setup', ['token' => $token, 'password' => 'noch-ein-passwort'], null, null), 'Zweite Einrichtung möglich', 409);
    $view = $page->handle('get', [], $session, null);
    check(str_contains(json_encode($view), 'Beispielpraxis'), 'Ansicht ohne gespeicherte Werte');
    problem(fn() => $page->handle('save', ['section' => 'practice', 'values' => ['name' => 'X']], $session, 'falsch'), 'Speichern ohne CSRF-Token', 403);
    $page->handle('save', ['section' => 'office_printing', 'values' => ['enabled' => true, 'queue' => 'Praxisdrucker', 'cups_server' => '', 'media' => 'A4', 'duplex' => false]], $session, $login['csrf']);
    check(Settings::effective($base)->get('office_printing', 'queue') === 'Praxisdrucker', 'Speichern über die Seite wirkungslos');
    $setup = $page->handle('printer_setup', [], $session, null);
    check($setup['inbox_key'] === Settings::effective($base)->get('invoice_inbox', 'key'), 'Druckereinrichtung mit falschem Schlüssel');

    // Server-IP-Adressen für Druckeradressen aus anderen Netzen (VPN).
    $page->network = new KienzleSumup\Network(fn(array $c) => ($c[2] ?? '') === 'addr' ? json_encode([
        ['ifname' => 'lo', 'addr_info' => [['family' => 'inet', 'local' => '127.0.0.1', 'prefixlen' => 8, 'scope' => 'host']]],
        ['ifname' => 'eth0', 'operstate' => 'UP', 'addr_info' => [['family' => 'inet', 'local' => '192.168.111.115', 'prefixlen' => 24, 'scope' => 'global'], ['family' => 'inet', 'local' => '8.8.8.8', 'prefixlen' => 32, 'scope' => 'global']]]]) : null);
    $withIps = $page->handle('get', [], $session, null);
    check($withIps['terminzettel']['ips'] === [['name' => 'eth0', 'ip' => '192.168.111.115']] && $withIps['invoice_printer']['ips'] === [['name' => 'eth0', 'ip' => '192.168.111.115']], 'Server-IP für Druckeradressen fehlt oder enthält öffentliche Adresse');
    $page->network = null;

    // Textschnipsel für die Rechnungserkennung und Eingangsübersicht.
    $page->handle('save', ['section' => 'invoice_inbox', 'values' => ['enabled' => true, 'max_age_days' => 14, 'label_number' => "Beleg-Nr.\nBeleg Nummer", 'label_total' => 'Endsumme']], $session, $login['csrf']);
    check(Settings::effective($base)->get('invoice_inbox', 'label_number') === ['Beleg-Nr.', 'Beleg Nummer'], 'Textschnipsel nicht gespeichert');
    problem(fn() => $page->handle('save', ['section' => 'invoice_inbox', 'values' => ['label_total' => str_repeat('x', 61)]], $session, $login['csrf']), 'Zu langer Textschnipsel akzeptiert', 422);
    $overview = $page->handle('inbox_overview', [], $session, null);
    check($overview['enabled'] === true && $overview['items'] === [] && $overview['rejected'] === [], 'Eingangsübersicht falsch');
    problem(fn() => $page->handle('inbox_overview', [], null, null), 'Eingangsübersicht ohne Anmeldung', 401);
    problem(fn() => $page->handle('inbox_delete', ['id' => str_repeat('a', 32)], $session, 'falsch'), 'Löschen ohne CSRF-Token', 403);
    problem(fn() => $page->handle('inbox_delete', ['id' => 'x'], $session, $login['csrf']), 'Ungültige ID beim Löschen', 422);
    problem(fn() => $page->handle('inbox_delete', ['id' => str_repeat('a', 32)], $session, $login['csrf']), 'Unbekannte Rechnung gelöscht', 404);
    problem(fn() => $page->handle('t2med_correct', ['id' => str_repeat('a', 32), 'change' => 'void'], $session, 'falsch'), 'Korrektur ohne CSRF-Token', 403);
    problem(fn() => $page->handle('t2med_correct', ['id' => str_repeat('a', 32), 'change' => 'amount', 'amount' => 'abc'], $session, $login['csrf']), 'Ungültiger Betrag bei Korrektur', 400);
    problem(fn() => $page->handle('t2med_correct', ['id' => str_repeat('a', 32), 'change' => 'void'], $session, $login['csrf']), 'Unbekannte Zahlung storniert', 404);

    for ($i = 0; $i < 5; $i++) { try { $admin->login('falsch'); } catch (Problem) {} }
    problem(fn() => $admin->login('ein-langes-passwort'), 'Keine Sperre nach Fehlversuchen', 429);
    $page->handle('logout', [], $session, $login['csrf']);
    check($admin->session($session) === null, 'Abmelden wirkungslos');

    check($admin->createSetupToken() === null, 'Einrichtungslink trotz Passwort erzeugt');
    $again = $admin->createSetupToken(true);
    check(is_string($again) && !$admin->configured(), '--admin-reset löscht das Passwort nicht');
    check(Settings::effective($base)->get('practice', 'name') === 'Beispielpraxis', '--admin-reset hat Einstellungen gelöscht');

    // Kaputte Überlagerung (z. B. andere Version): Datei gilt weiter.
    $db->query("UPDATE settings SET value='kaputt' WHERE key='config_overrides'");
    check(Settings::effective($base) === $base, 'Unlesbare Einstellungen nicht ignoriert');
    echo "Einstellungen und Admin-Zugang ok\n";
} finally {
    foreach ($dirs as $dir) if (is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
}
