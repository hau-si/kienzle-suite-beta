<?php
declare(strict_types=1);
// GOÄ-Rechner (Einfachsatz + Endbetrag → Faktor) und Liste bezahlter T2med-Rechnungen zum Abhaken.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{Admin, App, Config, Problem, ServiceCatalog, SettingsPage, TaxExport};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function problem(callable $fn, string $message, int $http): Problem {
    try { $fn(); } catch (Problem $e) { check($e->http === $http, $message . ' (HTTP ' . $e->http . ': ' . $e->getMessage() . ')'); return $e; }
    throw new RuntimeException($message);
}

$dirs = [];
try {
    // Rechner: nächstliegender Faktor mit zwei Nachkommastellen, Gebühr auf Cent gerundet.
    $r = ServiceCatalog::bestFactor(1072, 2500, 'standard');      // 25,00 / 10,72 = 2,332…
    check($r['factor'] === '2.33' && $r['price_cents'] === 2498 && $r['over_threshold'] && !$r['over_max'], 'Faktor falsch: ' . json_encode($r));
    $r = ServiceCatalog::bestFactor(466, 1072, 'standard');       // GOÄ 1 (4,66 €) × 2,3 = 10,72
    check($r['factor'] === '2.3' && $r['price_cents'] === 1072 && !$r['over_threshold'], 'Regelfall falsch: ' . json_encode($r));
    $r = ServiceCatalog::bestFactor(466, 2000, 'standard');
    check($r['over_max'] && $r['max_price_cents'] === 1631, 'Höchstsatz nicht erkannt: ' . json_encode($r));
    check(ServiceCatalog::bestFactor(1000, 500, 'standard')['factor'] === '1', 'Faktor unter 1,0 zugelassen');
    check(ServiceCatalog::bestFactor(1000, 1800, 'technical')['factor'] === '1.8' && ServiceCatalog::bestFactor(1000, 1300, 'lab')['factor'] === '1.3', 'Gebührenrahmen falsch');
    // Gegenprobe über viele Werte: kein anderer Faktor liegt näher am Ziel.
    for ($i = 0; $i < 300; $i++) {
        $base = random_int(100, 9000); $target = random_int($base, (int)($base * 3.5));
        $best = ServiceCatalog::bestFactor($base, $target, 'standard');
        $h = (int)round((float)$best['factor'] * 100);
        for ($k = 100; $k <= 350; $k++) check(abs(ServiceCatalog::fee($base, $k) - $target) >= abs($best['price_cents'] - $target), "Besserer Faktor $k für $base/$target");
        check(ServiceCatalog::fee($base, $h) === $best['price_cents'], 'Preis passt nicht zum Faktor');
    }

    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = $state = sys_get_temp_dir() . '/ks-goae-' . bin2hex(random_bytes(6)); $dirs[] = $state; mkdir($state, 0700);
    $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $v['sumup'] = ['mode' => 'mock']; $v['fhir'] = ['mode' => 'mock', 'entry_code' => 'ZAHLUNG'];
    $v['practice'] = ['name' => 'Praxis Beispiel', 'street' => 'Musterstraße 1', 'city' => '12345 Musterstadt'];
    $app = new App(new Config($v)); $app->db->migrate();
    check((int)$app->db->query('PRAGMA user_version')->fetchColumn() === 11, 'Datenbank nicht auf Version 11');
    $visit = function () use ($app): array {
        $launch = $app->launch(['context_id' => 'ctx-' . bin2hex(random_bytes(4)), 'oauth_token' => '', 'fhir_base_url' => '']);
        parse_str(parse_url($launch['url'], PHP_URL_FRAGMENT), $f); $e = $app->exchange($f['launch'], null);
        return $app->visit($app->browser($e['cookie']), $e['visit_id']);
    };
    $vis = $visit();
    // Mit Einfachsatz: Preis muss Einfachsatz × Faktor sein (±1 Cent).
    $app->saveService($vis, ['label' => 'Beratung', 'price' => '24,98', 'goae_code' => '3', 'factor' => '2,33', 'base' => '10,72']);
    check((int)$app->db->one("SELECT base_cents FROM services WHERE label='Beratung'")['base_cents'] === 1072, 'Einfachsatz nicht gespeichert');
    $e = problem(fn() => $app->saveService($vis, ['label' => 'Falsch', 'price' => '25,00', 'goae_code' => '3', 'factor' => '2,3', 'base' => '10,72']), 'Unpassender Preis akzeptiert', 400);
    check(str_contains($e->getMessage(), '24,66 €'), 'Fehlermeldung ohne richtigen Betrag: ' . $e->getMessage());
    $app->saveService($vis, ['label' => 'Ohne Rechner', 'price' => '25,00', 'goae_code' => '3', 'factor' => '2,3']);   // wie bisher ohne Einfachsatz
    check(in_array(1072, array_column($app->state($vis, ['csrf' => 'x'])['services'], 'base_cents'), true), 'Einfachsatz nicht an die Seite übergeben');

    // Bezahlte T2med-Rechnungen: Liste nach Rechnungsnummer, Abhaken, Export.
    $inbox = function (string $number, int $cents, string $patient) use ($app): string {
        $id = App::id();
        $app->db->query('INSERT INTO invoice_inbox(id,invoice_number,invoice_date,amount_cents,patient_name,patient_birthdate,received_at) VALUES(?,?,?,?,?,?,?)',
            [$id, $number, '2026-11-02', $cents, $patient, '1980-04-12', time()]);
        return $id;
    };
    $pay = function (string $inboxId, string $number, int $cents, string $reader, ?string $status = 'successful') use ($app, $visit): string {
        $id = App::id(); $vis = $visit();
        $app->db->query('INSERT INTO payments(id,visit_id,request_id,request_hash,reference,reader_id,amount_cents,services_json,payment_status,created_at,paid_at,transaction_id,inbox_id,t2med_invoice) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id, $vis['id'], App::id(), 'h', 'POS-' . strtoupper($id), $reader, $cents, '[]', $status, time(), time(), 'TX-' . $number, $inboxId, $number]);
        return $id;
    };
    $a = $pay($inbox('RE2026-0010', 4250, 'Erika Musterfrau'), 'RE2026-0010', 4250, 'rdr-1');
    $b = $pay($inbox('RE2026-0002', 1990, 'Max Mustermann'), 'RE2026-0002', 1990, 'rdr-1');
    $pay($inbox('RE2026-0003', 500, 'Test Person'), 'RE2026-0003', 500, 'mock-reader');
    $pay($inbox('RE2026-0004', 700, 'Abgebrochen'), 'RE2026-0004', 700, 'rdr-1', 'failed');
    $export = new TaxExport($app->db, $app->config);
    $open = $export->t2medPaid();
    check(array_column($open, 'number') === ['RE2026-0002', 'RE2026-0010'], 'Liste falsch sortiert oder gefiltert: ' . json_encode(array_column($open, 'number')));
    check($open[1]['patient'] === 'Erika Musterfrau' && $open[1]['amount_cents'] === 4250 && $open[1]['invoice_date'] === '2026-11-02', 'Rechnungsangaben fehlen');
    check(count($export->t2medPaid('open', 0, 0, true)) === 3, 'Testzahlung nicht auf Wunsch gezeigt');
    check($export->markT2medDone([$b], true) === 1 && array_column($export->t2medPaid(), 'number') === ['RE2026-0010'], 'Abhaken wirkt nicht');
    check(count($export->t2medPaid('all')) === 2 && $export->t2medPaid('all')[0]['done_date'] !== '', 'Alle-Ansicht falsch');
    check($export->markT2medDone([$b], false) === 1 && count($export->t2medPaid()) === 2, 'Zurücknehmen wirkt nicht');
    problem(fn() => $export->markT2medDone(['x'], true), 'Ungültige ID akzeptiert', 422);
    problem(fn() => $export->t2medPaid('open', 0, 5), 'Monat ohne Jahr akzeptiert', 422);
    $year = (int)date('Y');
    check(count($export->t2medPaid('all', $year)) === 2 && $export->t2medPaid('all', $year - 1) === [], 'Jahresfilter falsch');

    // Über die Einstellungsseite (Admin) und als Excel.
    $page = new SettingsPage($app->config);
    problem(fn() => $page->handle('t2med_paid', [], null, null), 'Liste ohne Anmeldung', 401);
    $admin = new Admin($app->db, $state);
    $login = $page->handle('setup', ['token' => $admin->createSetupToken(), 'password' => 'beispiel-passwort'], null, null);
    preg_match('/^ks_admin=([^;]+)/', (string)$page->cookie(), $m);
    check(count($page->handle('t2med_paid', ['status' => 'open'], $m[1], null)['rows']) === 2, 'Liste über die Seite falsch');
    problem(fn() => $page->handle('t2med_done', ['ids' => [$a]], $m[1], 'falsch'), 'Abhaken ohne CSRF', 403);
    $result = $page->handle('t2med_done', ['ids' => [$a], 'done' => true, 'filter' => ['status' => 'open']], $m[1], $login['csrf']);
    check(str_contains($result['message'], '1 Rechnung als in T2med abgehakt') && count($result['rows']) === 1 && $result['open_total'] === 1, 'Abhaken über die Seite falsch');
    $data = ''; $headers = [];
    $page->taxExport($m[1], ['kind' => 't2med', 'status' => 'all', 'year' => (string)$year], function ($h) use (&$headers) { $headers[] = $h; }, function ($x) use (&$data) { $data .= $x; });
    check(in_array('Content-Disposition: attachment; filename="T2med-Rechnungen-bezahlt-' . $year . '.xlsx"', $headers, true), 'Dateiname falsch: ' . json_encode($headers));
    file_put_contents($state . '/t2med.xlsx', $data);
    $out = shell_exec('python3 -I -c ' . escapeshellarg('import openpyxl,sys
ws=openpyxl.load_workbook(sys.argv[1])["T2med bezahlt"]
rows=list(ws.iter_rows(values_only=True))
assert rows[0][:5]==("Rechnungsnummer","Rechnungsdatum","Patient","Geburtsdatum","Betrag"), rows[0]
assert [r[0] for r in rows[1:-1]]==["RE2026-0002","RE2026-0010"], rows
assert rows[2][4]==42.5 and rows[2][9] is not None and rows[1][9] is None and rows[-1][0]=="Summe", rows
print("ok")') . ' ' . escapeshellarg($state . '/t2med.xlsx') . ' 2>&1');
    check(trim((string)$out) === 'ok', 'Excel-Liste fehlerhaft: ' . $out);
    echo "GOÄ-Rechner und T2med-Abhakliste ok\n";
} finally {
    foreach ($dirs as $dir) if (is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
}
