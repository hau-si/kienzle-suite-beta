<?php
declare(strict_types=1);
namespace KienzleSumup;

/** Eingegangene und abgelehnte T2med-Rechnungen für die Einstellungsseite (ohne Namen und Geburtsdaten). */
final class InboxOverview {
    /** Übersicht für die Einstellungsseite: eingegangene Rechnungen (ohne Klartext-Patientendaten) und abgelehnte Drucke. */
    public static function build(Database $db, Config $config): array {
        $limit = time() - $config->get('invoice_inbox', 'max_age_days', 14) * 86400;
        $items = [];
        foreach ($db->query('SELECT * FROM invoice_inbox ORDER BY received_at DESC LIMIT 30')->fetchAll() as $row) {
            $pay = $db->one("SELECT payment_status FROM payments WHERE t2med_invoice=? AND payment_status IN ('starting','pending','unknown','cancel_requested','successful') ORDER BY created_at DESC LIMIT 1", [$row['invoice_number']]);
            $status = $pay ? ($pay['payment_status'] === 'successful' ? 'bezahlt' : 'am Terminal') : ($row['superseded_at'] ? 'ersetzt' : ($row['received_at'] < $limit ? 'abgelaufen' : 'offen'));
            $initials = implode(' ', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)) . '.', array_slice(preg_split('/[\s,]+/u', trim($row['patient_name']), -1, PREG_SPLIT_NO_EMPTY), -2)));
            $items[] = ['number' => $row['invoice_number'], 'invoice_date' => $row['invoice_date'], 'amount_cents' => (int)$row['amount_cents'],
                'received_at' => (int)$row['received_at'], 'status' => $status, 'initials' => $initials, 'birth_year' => substr((string)$row['patient_birthdate'], 0, 4)];
        }
        return ['enabled' => (bool)$config->get('invoice_inbox', 'enabled', false), 'max_age_days' => $config->get('invoice_inbox', 'max_age_days', 14),
            'items' => $items, 'rejected' => (new InboxLog($config->get('app', 'state_dir')))->recent(10)];
    }
}
