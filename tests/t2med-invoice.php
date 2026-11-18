<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{App, Config, HttpClient, Problem, T2medInvoice};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn, string $message, int $http = 0): Problem {
    try { $fn(); } catch (Problem $e) { if ($http && $e->http !== $http) throw new RuntimeException($message . ' (HTTP ' . $e->http . ')'); return $e; }
    throw new RuntimeException($message);
}
// Nachbildung des pdftotext-Layouts einer T2med-Liquidation (fiktive Daten).
function invoiceText(string $number = 'RE2026-0042', string $total = '42,50', string $transfer = '42,50', string $patient = 'Erika Musterfrau , 12.04.1980'): string {
    return "Praxis Beispiel | Musterstraße 1 | 12345 Musterstadt\nFrau\nErika Musterfrau\n\n" .
        "Re.-Nr.:      $number\nRe.-Datum:    15.03.2026\nPatient:      $patient\n\nLIQUIDATION\n\n" .
        " Datum            Ziffer   Leistungstext                 Wert   Faktor      Betrag\n" .
        "15.03.2026          1      Beratung auch telefonisch     4,66 € 2,30         10,72 €\n" .
        "                                                     Gesamtsumme:            $total €\n" .
        "                         Seite   1 / 2\n\fRe.-Nr.: $number           Re.-Datum: 15.03.2026        Patient: Erika Musterfrau\n\n" .
        "Bitte überweisen Sie den Betrag von $transfer € ohne Abzug bis zum 14.04.2026.\n";
}
function configuration(bool $live = false): Config {
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = sys_get_temp_dir() . '/ks-t2med-invoice-' . bin2hex(random_bytes(6));
    $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $v['invoice_inbox'] = ['enabled' => true, 'key' => str_repeat('c', 64), 'max_age_days' => 14];
    if ($live) {
        $v['sumup'] = ['mode' => 'live', 'api_key' => 'sumup-secret', 'affiliate_key' => 'affiliate-secret', 'app_id' => 'test.app', 'merchant_code' => 'MTEST', 'reader_id' => 'reader-test'];
        $v['fhir'] = ['mode' => 'live', 'base_url' => 'https://t2med.test/aps/fhir/api/r4', 'launch_urls' => ['https://t2med.test/aps/fhir/api/r4'], 'api_key' => 'fhir-secret', 'entry_code' => 'ZAHLUNG', 'ca_file' => ''];
    }
    return new Config($v);
}
function visit(App $app, string $context = 'test'): array {
    $launch = $app->launch(['context_id' => $context, 'oauth_token' => 'secret-token', 'fhir_base_url' => 'https://t2med.test/aps/fhir/api/r4']);
    parse_str(parse_url($launch['url'], PHP_URL_FRAGMENT), $fragment);
    $exchange = $app->exchange($fragment['launch'], null); $browser = $app->browser($exchange['cookie']);
    return [$app->visit($browser, $exchange['visit_id']), $browser];
}
final class InvoiceFakeHttp extends HttpClient {
    public array $checkout = [];
    public string $status = 'SUCCESSFUL';
    public function request(string $method, string $url, array $headers, ?array $body = null, string $caFile = '', bool $pinCertificate = false): array {
        if (str_contains($url, '/Patient?')) return ['status' => 200, 'body' => ['resourceType' => 'Patient', 'id' => 'patient-1', 'birthDate' => '1980-04-12', 'name' => [['given' => ['Erika'], 'family' => 'Musterfrau']]]];
        check(str_starts_with($url, 'https://api.sumup.com/'), 'Unerwarteter Netzwerkaufruf');
        if ($method === 'POST' && str_ends_with($url, '/checkout')) { $this->checkout = $body; return ['status' => 201, 'body' => ['data' => ['checkout_id' => 'checkout-1', 'client_transaction_id' => 'client-1']]]; }
        if (str_contains($url, '/transactions?')) return ['status' => 200, 'body' => ['id' => 'tx-1', 'client_transaction_id' => 'client-1', 'merchant_code' => 'MTEST',
            'currency' => 'EUR', 'amount' => $this->checkout['total_amount']['value'] / 100, 'status' => $this->status, 'timestamp' => '2026-03-15T10:00:00Z']];
        throw new RuntimeException('Unerwarteter API-Pfad ' . $url);
    }
}

$dirs = [];
try {
    // 1. Parser: Werte, Gegenprobe und Eindeutigkeit.
    $parsed = T2medInvoice::parse(invoiceText(), 100000);
    check($parsed === ['number' => 'RE2026-0042', 'invoice_date' => '2026-03-15', 'amount_cents' => 4250, 'patient_name' => 'Erika Musterfrau', 'patient_birthdate' => '1980-04-12'], 'Rechnung falsch gelesen');
    check(T2medInvoice::parse(invoiceText(total: '1.234,50', transfer: '1.234,50'), 1000000)['amount_cents'] === 123450, 'Tausenderpunkt falsch gelesen');
    rejects(fn() => T2medInvoice::parse(invoiceText(transfer: '57,88'), 100000), 'Widersprüchlicher Zahlbetrag akzeptiert', 422);
    rejects(fn() => T2medInvoice::parse(invoiceText() . "Gesamtsumme: 12,00 €\n", 100000), 'Zweite Gesamtsumme akzeptiert', 422);
    rejects(fn() => T2medInvoice::parse(invoiceText() . "Re.-Nr.: RE2026-0043\n", 100000), 'Zweite Rechnungsnummer akzeptiert', 422);
    rejects(fn() => T2medInvoice::parse(invoiceText(patient: 'Erika Musterfrau'), 100000), 'Rechnung ohne Geburtsdatum akzeptiert', 422);
    rejects(fn() => T2medInvoice::parse(invoiceText(total: '-42,50', transfer: '-42,50'), 100000), 'Negative Rechnung (Gutschrift) akzeptiert', 422);
    rejects(fn() => T2medInvoice::parse(invoiceText(total: '2.000,00', transfer: '2.000,00'), 100000), 'Betrag über Maximum akzeptiert', 422);
    rejects(fn() => T2medInvoice::parse('', 100000), 'Leere Rechnung akzeptiert', 422);
    rejects(fn() => T2medInvoice::fromPdf('kein pdf', 100000), 'Nicht-PDF akzeptiert', 415);

    // 2. Patientenabgleich.
    check(T2medInvoice::matches($parsed, 'Erika Musterfrau', '1980-04-12'), 'Passender Patient abgelehnt');
    check(T2medInvoice::matches(['patient_name' => 'Dr. Erika  Musterfrau'] + $parsed, 'erika musterfrau', '1980-04-12'), 'Titel/Schreibweise nicht toleriert');
    check(!T2medInvoice::matches($parsed, 'Erika Musterfrau', '1980-04-13'), 'Falsches Geburtsdatum akzeptiert');
    check(!T2medInvoice::matches($parsed, 'Erik Musterfrau', '1980-04-12'), 'Anderer Name akzeptiert');
    check(!T2medInvoice::matches($parsed, 'Erika Musterfrau', ''), 'Fehlendes Geburtsdatum akzeptiert');

    // 3. Ablauf im Testmodus: Eingang, Anzeige, Kassieren, Schutz vor Doppelzahlung.
    $cfg = configuration(); $dirs[] = $cfg->get('app', 'state_dir'); $app = new App($cfg); $app->db->migrate();
    [$v, $b] = visit($app);
    check($app->state($v, $b)['invoices'] === [], 'Rechnung ohne Eingang angezeigt');
    $stored = $app->storeInvoice($parsed);
    check(!str_contains(json_encode($stored, JSON_UNESCAPED_UNICODE), 'Musterfrau'), 'Antwort an Druckserver enthält Patientendaten');
    $app->storeInvoice(['number' => 'RE2026-0999', 'patient_birthdate' => '1990-01-01'] + $parsed);
    $app->storeInvoice(['number' => 'RE2026-0998', 'patient_name' => 'Max Mustermann'] + $parsed);
    $open = $app->state($v, $b)['invoices'];
    check(count($open) === 1 && $open[0]['number'] === 'RE2026-0042' && $open[0]['amount_cents'] === 4250, 'Fremde Rechnung angezeigt oder eigene fehlt');
    // Erneuter Druck derselben Nummer ersetzt die ältere Fassung.
    $app->storeInvoice(['amount_cents' => 4300] + $parsed);
    $open = $app->state($v, $b)['invoices'];
    check(count($open) === 1 && $open[0]['amount_cents'] === 4300, 'Neuere Fassung ersetzt alte nicht');
    rejects(fn() => $app->startInvoice($v, ['request_id' => App::id(), 'invoice_id' => str_repeat('0', 32)]), 'Unbekannte Rechnung gestartet', 409);
    $other = $app->db->one("SELECT id FROM invoice_inbox WHERE invoice_number='RE2026-0999'")['id'];
    rejects(fn() => $app->startInvoice($v, ['request_id' => App::id(), 'invoice_id' => $other]), 'Rechnung eines anderen Patienten gestartet', 409);
    $request = ['request_id' => App::id(), 'invoice_id' => $open[0]['id']];
    $payment = $app->startInvoice($v, $request);
    check($payment['payment_status'] === 'pending' && $payment['amount_cents'] === 4300 && $payment['t2med_invoice'] === 'RE2026-0042', 'Rechnungszahlung nicht gestartet');
    check($app->startInvoice($v, $request)['id'] === $payment['id'], 'Wiederholter Start erzeugt zweite Zahlung');
    rejects(fn() => $app->startInvoice($v, ['request_id' => $request['request_id'], 'invoice_id' => $other]), 'Vorgangsschlüssel für andere Rechnung wiederverwendet', 409);
    check($app->state($v, $b)['invoices'] === [], 'Laufende Rechnung weiter angeboten');
    rejects(fn() => $app->storeInvoice($parsed), 'Laufende Rechnung erneut angenommen', 409);
    $app->mock($v, ['payment_id' => $payment['id'], 'status' => 'failed']);
    check(count($app->state($v, $b)['invoices']) === 1, 'Nach abgelehnter Zahlung nicht erneut kassierbar');
    $retry = $app->startInvoice($v, ['request_id' => App::id(), 'invoice_id' => $open[0]['id']]);
    $app->mock($v, ['payment_id' => $retry['id'], 'status' => 'successful']);
    $e = rejects(fn() => $app->storeInvoice($parsed), 'Bezahlte Rechnung erneut angenommen', 409);
    check(str_contains($e->getMessage(), 'bereits'), 'Unklare Meldung bei bezahlter Rechnung');
    $done = $app->document($v, $retry['id']);
    check($done['doc_status'] === 'written', 'Rechnungszahlung nicht dokumentiert');
    $record = json_decode($app->db->one('SELECT resource_json FROM mock_records WHERE payment_id=?', [$retry['id']])['resource_json'], true);
    check(str_contains($record['valueString'], 'T2med-Rechnung RE2026-0042 vom 15.03.2026 43,00 €'), 'Akteneintrag ohne Rechnungsbezug');
    // Neuer Behandlungskontext desselben Patienten: bezahlte Rechnung nicht erneut anbieten.
    [$v2, $b2] = visit($app, 'test-2');
    check($app->state($v2, $b2)['invoices'] === [], 'Bezahlte Rechnung in neuem Kontext angeboten');
    // Abgelaufene, nie kassierte Rechnungen werden nicht angeboten und später entfernt.
    $app->db->query("UPDATE invoice_inbox SET received_at=? WHERE invoice_number='RE2026-0998'", [time() - 15 * 86400]);
    $app->storeInvoice(['number' => 'RE2026-0997'] + $parsed);
    check(!$app->db->one("SELECT id FROM invoice_inbox WHERE invoice_number='RE2026-0998'"), 'Abgelaufene Rechnung nicht entfernt');
    check($app->db->one('SELECT id FROM invoice_inbox WHERE id=?', [$open[0]['id']]) !== null, 'Kassierte Rechnung entfernt');
    // Deaktivierter Eingang.
    $off = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $off['app']['state_dir'] = sys_get_temp_dir() . '/ks-t2med-invoice-off-' . bin2hex(random_bytes(6)); $dirs[] = $off['app']['state_dir'];
    $off['app']['launcher_key'] = str_repeat('a', 64); $off['app']['encryption_key'] = str_repeat('b', 64);
    $offApp = new App(new Config($off)); $offApp->db->migrate();
    rejects(fn() => $offApp->storeInvoice($parsed), 'Deaktivierter Eingang nimmt Rechnung an', 404);

    // 4. Live-Daten an SumUp: nur Betrag, Rechnungsnummer und neutrale Referenz.
    $cfg = configuration(true); $dirs[] = $cfg->get('app', 'state_dir'); $http = new InvoiceFakeHttp(); $app = new App($cfg, $http); $app->db->migrate();
    [$v, $b] = visit($app);
    $app->storeInvoice($parsed);
    $id = $app->state($v, $b)['invoices'][0]['id'];
    $payment = $app->startInvoice($v, ['request_id' => App::id(), 'invoice_id' => $id]);
    check($http->checkout['total_amount'] === ['currency' => 'EUR', 'minor_unit' => 2, 'value' => 4250], 'Falscher Betrag an SumUp');
    check($http->checkout['description'] === 'Rechnung RE2026-0042', 'Rechnungsnummer nicht als SumUp-Beschreibung');
    check($http->checkout['affiliate']['foreign_transaction_id'] === $payment['reference'], 'Neutrale Referenz fehlt');
    check(!str_contains(json_encode($http->checkout, JSON_UNESCAPED_UNICODE), 'Musterfrau') && !str_contains(json_encode($http->checkout), '1980'), 'Patientendaten an SumUp gesendet');
    check($app->poll($v, $payment['id'])['payment_status'] === 'successful', 'Erfolg nicht übernommen');

    echo "OK: T2med-Rechnung lesen, Patientenabgleich, Ersetzen, Doppelzahlungsschutz, Akteneintrag und neutrale SumUp-Daten.\n";
} finally {
    foreach ($dirs as $dir) if (is_dir($dir)) { array_map('unlink', glob($dir . '/*') ?: []); @rmdir($dir); }
}
