<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Excel-Liste für die Steuerberatung: alle im Rechnungstool erstellten und per Karte
 * bezahlten Rechnungen eines Jahres oder Monats. T2med-Rechnungen (virtueller Drucker)
 * gehören nicht dazu; sie werden in T2med geführt. Testzahlungen nur auf ausdrücklichen Wunsch.
 */
final class TaxExport {
    private const MONTHS = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    public function __construct(private Database $db, private Config $config) {}

    public static function filename(int $year, int $month): string {
        return 'Rechnungen-' . $year . ($month ? '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) : '') . '.xlsx';
    }

    /** Jahre mit Zahlungen (für die Auswahl), neuestes zuerst; mindestens das aktuelle Jahr. */
    public function years(): array {
        $zone = new \DateTimeZone($this->config->get('app', 'timezone'));
        $years = [(int)(new \DateTimeImmutable('now', $zone))->format('Y')];
        $range = $this->db->one("SELECT MIN(paid_at) AS first FROM payments WHERE payment_status='successful' AND paid_at IS NOT NULL AND t2med_invoice IS NULL");
        if ($range && $range['first']) {
            $first = (int)(new \DateTimeImmutable('@' . $range['first']))->setTimezone($zone)->format('Y');
            for ($y = $years[0] - 1; $y >= $first; $y--) $years[] = $y;
        }
        return $years;
    }

    /** Rechnungen des Zeitraums als Liste aufbereiteter Datensätze (Beträge in Cent). */
    public function invoices(int $year, int $month, bool $includeTest = false): array {
        if ($year < 2000 || $year > 2100 || $month < 0 || $month > 12) throw new Problem('Ungültiger Zeitraum.', 422);
        $zone = new \DateTimeZone($this->config->get('app', 'timezone'));
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month ?: 1), $zone);
        $to = $month ? $from->modify('+1 month') : $from->modify('+1 year');
        $rows = $this->db->query("SELECT * FROM payments WHERE payment_status='successful' AND paid_at>=? AND paid_at<? AND t2med_invoice IS NULL ORDER BY paid_at, rowid",
            [$from->getTimestamp(), $to->getTimestamp()])->fetchAll();
        $out = [];
        foreach ($rows as $payment) {
            $invoice = $payment['invoice_json'] ? json_decode($payment['invoice_json'], true, 32, JSON_THROW_ON_ERROR) : null;
            $test = $invoice ? !empty($invoice['mock']) : $payment['reader_id'] === 'mock-reader';
            if ($test && !$includeTest) continue;
            $items = $invoice['items'] ?? json_decode($payment['services_json'], true, 32, JSON_THROW_ON_ERROR);
            $paid = (new \DateTimeImmutable('@' . $payment['paid_at']))->setTimezone($zone);
            $out[] = ['date' => $paid->format('Y-m-d'), 'month' => (int)$paid->format('n'), 'number' => (string)($payment['transaction_id'] ?? ''),
                'service_date' => $invoice['service_date'] ?? '', 'patient' => $invoice['patient']['name'] ?? '',
                'kind' => $invoice ? ($invoice['title'] === 'PRIVATÄRZTLICHE RECHNUNG' ? 'Privatärztliche Rechnung' : 'Leistungsbeleg') : 'Zahlung ohne Beleg',
                'small_business' => !empty($invoice['tax']['small_business']), 'test' => $test,
                'gross' => (int)$payment['amount_cents'], 'items' => $items, 'totals' => Tax::totals($items), 'reference' => $payment['reference']];
        }
        return $out;
    }

    /** XLSX mit den Blättern Rechnungen, Positionen und Monatssummen. */
    public function workbook(int $year, int $month, bool $names = false, bool $includeTest = false): string {
        $invoices = $this->invoices($year, $month, $includeTest);
        $rates = [];
        foreach ($invoices as $invoice) foreach ($invoice['totals'] as $rate => $_) if ($rate > 0) $rates[$rate] = true;
        krsort($rates); $rates = array_keys($rates);

        $columns = [['title' => 'Rechnungsdatum', 'type' => 'date', 'width' => 16], ['title' => 'Rechnungsnummer', 'width' => 38], ['title' => 'Leistungsdatum', 'type' => 'date', 'width' => 16]];
        if ($names) $columns[] = ['title' => 'Patient', 'width' => 26];
        array_push($columns, ['title' => 'Art', 'width' => 24], ['title' => 'Brutto', 'type' => 'money', 'width' => 12]);
        foreach ($rates as $rate) array_push($columns, ['title' => 'Netto ' . Tax::percent($rate), 'type' => 'money', 'width' => 13], ['title' => 'MwSt. ' . Tax::percent($rate), 'type' => 'money', 'width' => 13]);
        array_push($columns, ['title' => 'Ohne MwSt.', 'type' => 'money', 'width' => 12], ['title' => 'Umsatzsteuer-Hinweis', 'width' => 24],
            ['title' => 'Zahlungsart', 'width' => 14], ['title' => 'Zahlungsreferenz', 'width' => 40]);
        if ($includeTest) $columns[] = ['title' => 'Testzahlung', 'width' => 12];
        $rows = [];
        foreach ($invoices as $invoice) {
            $row = [$invoice['date'], $invoice['number'], $invoice['service_date'] ?: null];
            if ($names) $row[] = $invoice['patient'];
            array_push($row, $invoice['kind'], $invoice['gross']);
            foreach ($rates as $rate) array_push($row, $invoice['totals'][$rate]['net'] ?? null, $invoice['totals'][$rate]['vat'] ?? null);
            array_push($row, $invoice['totals'][0]['gross'] ?? null, $invoice['small_business'] ? 'Kleinunternehmer § 19 UStG' : '', 'Karte (SumUp)', $invoice['reference']);
            if ($includeTest) $row[] = $invoice['test'] ? 'ja' : '';
            $rows[] = $row;
        }
        $xlsx = new Xlsx();
        $xlsx->sheet('Rechnungen', $columns, $rows, true);

        $items = [];
        foreach ($invoices as $invoice) foreach ($invoice['items'] as $item) {
            $rate = (int)($item['vat_rate'] ?? 0); $gross = (int)$item['price_cents']; $vat = Tax::included($gross, $rate);
            $items[] = [$invoice['date'], $invoice['number'], (string)($item['goae_code'] ?? ''), (string)$item['label'],
                ($item['factor'] ?? '') !== '' ? str_replace('.', ',', (string)$item['factor']) : '', $gross, $rate, $vat, $gross - $vat];
        }
        $xlsx->sheet('Positionen', [['title' => 'Rechnungsdatum', 'type' => 'date', 'width' => 16], ['title' => 'Rechnungsnummer', 'width' => 38],
            ['title' => 'GOÄ', 'width' => 8], ['title' => 'Leistung', 'width' => 40], ['title' => 'Faktor', 'width' => 8],
            ['title' => 'Brutto', 'type' => 'money', 'width' => 12], ['title' => 'MwSt.-Satz', 'type' => 'percent', 'width' => 11],
            ['title' => 'MwSt.', 'type' => 'money', 'width' => 12], ['title' => 'Netto', 'type' => 'money', 'width' => 12]], $items, true);

        $sumColumns = [['title' => 'Monat', 'width' => 14], ['title' => 'Anzahl', 'type' => 'int', 'width' => 9], ['title' => 'Brutto', 'type' => 'money', 'width' => 13]];
        foreach ($rates as $rate) array_push($sumColumns, ['title' => 'Netto ' . Tax::percent($rate), 'type' => 'money', 'width' => 13], ['title' => 'MwSt. ' . Tax::percent($rate), 'type' => 'money', 'width' => 13]);
        $sumColumns[] = ['title' => 'Ohne MwSt.', 'type' => 'money', 'width' => 13];
        $months = [];
        foreach ($month ? [$month] : range(1, 12) as $m) $months[$m] = array_merge([self::MONTHS[$m] . ' ' . $year, 0, 0], array_fill(0, count($rates) * 2 + 1, 0));
        foreach ($invoices as $invoice) {
            $row = &$months[$invoice['month']];
            $row[1]++; $row[2] += $invoice['gross'];
            foreach ($rates as $i => $rate) { $row[3 + 2 * $i] += $invoice['totals'][$rate]['net'] ?? 0; $row[4 + 2 * $i] += $invoice['totals'][$rate]['vat'] ?? 0; }
            $row[3 + 2 * count($rates)] += $invoice['totals'][0]['gross'] ?? 0;
            unset($row);
        }
        $xlsx->sheet($month ? 'Summe' : 'Monatssummen', $sumColumns, array_values($months), true);
        return $xlsx->build();
    }
}
