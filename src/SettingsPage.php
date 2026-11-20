<?php
declare(strict_types=1);
namespace KienzleSumup;

/** JSON-Aktionen der Einstellungsseite. Alles außer Status, Ersteinrichtung und Anmeldung braucht eine Admin-Sitzung. */
final class SettingsPage {
    private Database $db;
    private Admin $admin;
    private ?string $cookie = null;

    public function __construct(private Config $base, private ?HttpClient $http = null) {
        $this->db = new Database($base->get('app', 'state_dir'));
        $this->db->migrate();
        $this->admin = new Admin($this->db, $base->get('app', 'state_dir'));
    }

    public function cookie(): ?string { return $this->cookie; }

    public function handle(string $action, array $input, ?string $sessionToken, ?string $csrf): array {
        if ($action === 'status') {
            $session = $this->admin->session($sessionToken);
            return ['setup_required' => !$this->admin->configured(), 'logged_in' => (bool)$session, 'csrf' => $session['csrf'] ?? null];
        }
        if ($action === 'setup' || $action === 'login') {
            $result = $action === 'setup'
                ? $this->admin->setup(App::string($input, 'token', 100), App::string($input, 'password', 400))
                : $this->admin->login(App::string($input, 'password', 400));
            $this->cookie = Admin::cookie($result['token']);
            return ['logged_in' => true, 'csrf' => $result['csrf']];
        }
        $session = $this->admin->session($sessionToken);
        if (!$session) throw new Problem('Bitte anmelden.', 401);
        if ($action !== 'get' && $action !== 'printer_setup' && !hash_equals($session['csrf'], (string)$csrf)) throw new Problem('Sitzung abgelaufen. Bitte Seite neu laden.', 403);
        return match ($action) {
            'get' => $this->view(),
            'save' => $this->save(App::string($input, 'section', 40), is_array($input['values'] ?? null) ? $input['values'] : []),
            'test' => ['message' => $this->test(App::string($input, 'kind', 40))],
            'password' => $this->password($input),
            'logout' => $this->logout($sessionToken),
            'printer_setup' => $this->printerSetup(),
            default => throw new Problem('Unbekannte Aktion.', 404),
        };
    }

    private function config(): Config { return Settings::effective($this->base); }

    private function view(): array {
        $config = $this->config();
        $tools = [];
        foreach (['pdftotext' => '/usr/bin/pdftotext', 'smbclient' => '/usr/bin/smbclient', 'lp' => '/usr/bin/lp', 'rsvg-convert' => '/usr/bin/rsvg-convert'] as $name => $path) $tools[$name] = is_executable($path);
        $broken = Settings::read($this->base) && $config === $this->base;
        return ['sections' => Settings::view($config), 'tools' => $tools + ['gd' => extension_loaded('gd')],
            'base_url' => $config->get('app', 'base_url'), 'version' => trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION')),
            'mode' => $config->get('sumup', 'mode'), 'fhir_mode' => $config->get('fhir', 'mode'),
            'warning' => $broken ? 'Gespeicherte Einstellungen passen nicht zur aktuellen Version und werden ignoriert. Bitte die Bereiche prüfen und neu speichern.' : null,
            'invoice_printer' => (bool)$config->get('invoice_inbox', 'enabled', false)];
    }

    private function save(string $section, array $values): array {
        Settings::save($this->db, $this->base, $section, $values);
        return $this->view() + ['message' => 'Gespeichert. Gilt ab sofort für alle Arbeitsplätze.'];
    }

    private function test(string $kind): string {
        $config = $this->config();
        $mock = $config->get('sumup', 'mode') !== 'live';
        switch ($kind) {
            case 'sumup':
                return (new SumUpClient($config, $this->http ?? new HttpClient()))->check();
            case 'receipt_printer':
                if (!$config->get('printing', 'enabled', false)) throw new Problem('Bondrucker ist nicht aktiviert.', 409);
                // ESC/POS-Text: Initialisieren, Text, Vorschub, Schnitt.
                (new ReceiptPrinter($config))->send("\x1b@Kienzle-SumUp Testdruck\n" . date('d.m.Y H:i') . "\n\n\n\n\x1dVB\x00");
                return 'Testbon an den Bondrucker übergeben.';
            case 'office_printer':
                $office = new OfficePrinter($config);
                if (!$office->enabled()) throw new Problem('Praxisdrucker ist nicht aktiviert.', 409);
                $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="210mm" height="297mm" viewBox="0 0 210 297"><rect width="210" height="297" fill="white"/>'
                    . '<text x="20" y="30" font-family="DejaVu Sans" font-size="8">Kienzle-SumUp Testseite</text><text x="20" y="42" font-family="DejaVu Sans" font-size="4">' . date('d.m.Y H:i') . '</text></svg>';
                return 'Testseite übergeben: Auftrag ' . $office->send(ReceiptPdf::convert($svg), 'Kienzle-SumUp Testseite') . '.';
            case 'export':
                return (new InvoiceExport($config))->check();
            default:
                throw new Problem('Unbekannter Test.', 404);
        }
    }

    private function password(array $input): array {
        $this->admin->changePassword(App::string($input, 'current', 400), App::string($input, 'new', 400));
        $result = $this->admin->login(App::string($input, 'new', 400));
        $this->cookie = Admin::cookie($result['token']);
        return ['csrf' => $result['csrf'], 'message' => 'Passwort geändert.'];
    }

    private function logout(?string $token): array {
        $this->admin->logout($token);
        $this->cookie = Admin::cookie('', 0);
        return ['logged_in' => false];
    }

    /** Einrichtungsdatei für einen entfernten Druckserver (Schlüssel und Serverzertifikat). */
    private function printerSetup(): array {
        $config = $this->config();
        if (!$config->get('invoice_inbox', 'enabled', false)) throw new Problem('Zuerst „T2med-Rechnungen übernehmen“ aktivieren und speichern.', 409);
        $cert = @file_get_contents('/etc/kienzle-sumup/server.crt');
        return ['server_url' => $config->get('app', 'base_url'), 'inbox_key' => $config->get('invoice_inbox', 'key'),
            'certificate' => $cert && !str_contains($cert, 'PRIVATE KEY') ? $cert : ''];
    }
}
