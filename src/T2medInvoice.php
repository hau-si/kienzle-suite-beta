<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Liest Rechnungsnummer, Datum, Gesamtbetrag und Patient aus einer T2med-Liquidation.
 * Nur eindeutige Angaben werden akzeptiert; jede Abweichung führt zur Ablehnung,
 * damit nie ein falscher Betrag am Terminal erscheint.
 */
final class T2medInvoice {
    public const MAX_PDF = 5 * 1024 * 1024;

    public static function fromPdf(string $pdf, int $maxCents): array {
        if (strlen($pdf) > self::MAX_PDF) throw new Problem('Die Rechnung ist zu groß.', 413);
        if (!str_starts_with($pdf, '%PDF-')) throw new Problem('Bitte die Rechnung als PDF übergeben.', 415);
        $binary = null;
        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/homebrew/bin/pdftotext'] as $path) {
            if (is_executable($path)) { $binary = $path; break; }
        }
        if (!$binary) throw new Problem('pdftotext fehlt. Bitte den aktualisierten Server-Installer ausführen (poppler-utils).', 503);
        $result = Command::run([$binary, '-layout', '-enc', 'UTF-8', 'fd://0', '-'], $pdf, 20, 2 * 1024 * 1024);
        if ($result['code'] !== 0) throw new Problem('Die Rechnung konnte nicht gelesen werden.', 422);
        return self::parse($result['output'], $maxCents);
    }

    public static function parse(string $text, int $maxCents): array {
        if (!mb_check_encoding($text, 'UTF-8')) throw new Problem('Die Rechnung enthält keinen lesbaren Text.', 422);
        $text = str_replace(["\u{00a0}", "\r"], [' ', ''], $text);
        if (trim($text) === '') throw new Problem('Die Rechnung enthält keinen Text. Bitte als Text-PDF aus T2med drucken.', 422);

        $number = self::single($text, '/Re\.-Nr\.:[ \t]*([A-Za-z0-9][A-Za-z0-9\/._-]{0,39})/u', 'Rechnungsnummer', 'Re.-Nr.:');
        $date = self::date(self::single($text, '/Re\.-Datum:[ \t]*(\d{2}\.\d{2}\.\d{4})/u', 'Rechnungsdatum', 'Re.-Datum:'));
        $total = self::cents(self::single($text, '/Gesamtsumme:[ \t]*(-?[\d.]+,\d{2})[ \t]*€/u', 'Gesamtsumme', 'Gesamtsumme: … €'));
        // Gegenprobe mit dem Überweisungssatz, falls vorhanden.
        if (preg_match_all('/Betrag von[ \t]*(-?[\d.]+,\d{2})[ \t]*€/u', $text, $m)) {
            foreach ($m[1] as $value) if (self::cents($value) !== $total) throw new Problem('Gesamtsumme und Zahlbetrag der Rechnung widersprechen sich.', 422);
        }
        if ($total < 1 || $total > $maxCents) throw new Problem('Der Rechnungsbetrag liegt außerhalb des erlaubten Bereichs.', 422);

        // Erste Seite: „Patient: Vorname Nachname , TT.MM.JJJJ“. Folgeseiten ohne Geburtsdatum.
        preg_match_all('/Patient:[ \t]*([^\n,]{1,160}?)[ \t]*,[ \t]*(\d{2}\.\d{2}\.\d{4})/u', $text, $p, PREG_SET_ORDER);
        $patients = [];
        foreach ($p as $match) $patients[self::name($match[1]) . '|' . $match[2]] = [trim($match[1]), $match[2]];
        if (count($patients) !== 1) throw new Problem('Patient und Geburtsdatum der Rechnung sind nicht eindeutig.', 422);
        [$name, $birth] = array_values($patients)[0];
        if (self::name($name) === '') throw new Problem('Der Patientenname der Rechnung fehlt.', 422);

        return ['number' => $number, 'invoice_date' => $date, 'amount_cents' => $total,
            'patient_name' => mb_substr(preg_replace('/\s+/u', ' ', $name), 0, 160), 'patient_birthdate' => self::date($birth)];
    }

    /** Vergleichsform für Namen: Kleinschreibung, einfache Leerzeichen. */
    public static function name(string $value): string {
        return mb_strtolower(trim(preg_replace('/[\s,]+/u', ' ', $value)));
    }

    /** Passt die Rechnung zum Patienten aus dem T2med-Kontext? */
    public static function matches(array $invoice, string $patientName, string $patientBirthdate): bool {
        if ($patientBirthdate === '' || $invoice['patient_birthdate'] !== $patientBirthdate) return false;
        $pdf = self::name($invoice['patient_name']); $fhir = self::name($patientName);
        // Ein vorangestellter Titel (z. B. „Dr.“) auf der Rechnung ist erlaubt.
        return $fhir !== '' && ($pdf === $fhir || str_ends_with($pdf, ' ' . $fhir));
    }

    private static function single(string $text, string $pattern, string $label, string $hint): string {
        preg_match_all($pattern, $text, $m);
        $values = array_values(array_unique($m[1] ?? []));
        if (count($values) === 0) throw new Problem($label . ' der Rechnung nicht gefunden (im PDF-Text fehlt die Bezeichnung „' . $hint . '“).', 422);
        if (count($values) > 1) throw new Problem($label . ' der Rechnung ist nicht eindeutig (' . count($values) . ' verschiedene Werte).', 422);
        return $values[0];
    }
    private static function cents(string $value): int {
        if (!preg_match('/^(-?)(\d{1,3}(?:\.\d{3})*|\d+),(\d{2})$/D', $value, $m)) throw new Problem('Betrag der Rechnung ist nicht lesbar.', 422);
        $cents = (int)str_replace('.', '', $m[2]) * 100 + (int)$m[3];
        return $m[1] === '-' ? -$cents : $cents;
    }
    private static function date(string $value): string {
        $d = \DateTimeImmutable::createFromFormat('!d.m.Y', $value);
        if (!$d || $d->format('d.m.Y') !== $value) throw new Problem('Datum der Rechnung ist ungültig.', 422);
        return $d->format('Y-m-d');
    }
}
