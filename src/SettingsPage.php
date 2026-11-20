<?php
declare(strict_types=1);
namespace KienzleSumup;

/** JSON-Aktionen der Einstellungsseite. Alles außer Status, Ersteinrichtung und Anmeldung braucht eine Admin-Sitzung. */
final class SettingsPage {
    private Database $db;
    private Admin $admin;
    private ?string $cookie = null;

    private PrinterAdmin $printers;

    public function __construct(private Config $base, private ?HttpClient $http = null, ?PrinterAdmin $printers = null) {
        $this->printers = $printers ?? new PrinterAdmin();
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
        if (!in_array($action, ['get', 'printer_setup', 'printers'], true) && !hash_equals($session['csrf'], (string)$csrf)) throw new Problem('Sitzung abgelaufen. Bitte Seite neu laden.', 403);
        return match ($action) {
            'get' => $this->view(),
            'save' => $this->save(App::string($input, 'section', 40), is_array($input['values'] ?? null) ? $input['values'] : []),
            'test' => ['message' => $this->test(App::string($input, 'kind', 40))],
            'password' => $this->password($input),
            'logout' => $this->logout($sessionToken),
            'printer_setup' => $this->printerSetup(),
            'printers' => $this->printerList(),
            'printer_discover' => ['found' => $this->printerReady()->discover()],
            'printer_add' => $this->printerAdd($input),
            'printer_use' => $this->printerUse(App::string($input, 'name', 41), App::string($input, 'kind', 20)),
            'printer_remove' => $this->printerRemove(App::string($input, 'name', 41)),
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
            'invoice_printer' => (bool)$config->get('invoice_inbox', 'enabled', false), 'clients' => $this->clients()];
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

    private function printerReady(): PrinterAdmin {
        if (!$this->printers->available()) throw new Problem('Druckdienst fehlt auf diesem Server. Bitte einmal sudo bash scripts/install-server.sh ausführen.', 503);
        return $this->printers;
    }
    private function printerList(): array {
        $config = $this->config();
        $receipt = $config->get('printing', 'enabled', false) && $config->get('printing', 'transport', 'samba') === 'cups' && $config->get('printing', 'cups_server', '') === '' ? $config->get('printing', 'queue') : null;
        $office = $config->get('office_printing', 'enabled', false) && $config->get('office_printing', 'cups_server', '') === '' ? $config->get('office_printing', 'queue') : null;
        if (!$this->printers->available()) return ['available' => false, 'queues' => [], 'kinds' => PrinterAdmin::KINDS];
        $queues = array_map(fn($q) => $q + ['role' => $q['name'] === $receipt ? 'receipt' : ($q['name'] === $office ? 'office' : null)], $this->printers->queues());
        return ['available' => true, 'queues' => $queues, 'kinds' => PrinterAdmin::KINDS];
    }
    private function printerAdd(array $input): array {
        $kind = App::string($input, 'kind', 20, false);
        $added = $this->printerReady()->add($kind, App::string($input, 'name', 41, false), App::string($input, 'address', 320, false));
        $this->assign($added['name'], $kind);
        return $this->view() + ['printers' => $this->printerList(), 'added' => $added['name'],
            'message' => 'Drucker „' . $added['name'] . '“ eingerichtet und als ' . ($kind === 'office' ? 'Praxisdrucker (A4)' : 'Bondrucker') . ' übernommen. Jetzt „Testdruck“ wählen.'];
    }
    private function printerUse(string $name, string $kind): array {
        if (!isset(PrinterAdmin::KINDS[$kind])) throw new Problem('Bitte wählen, wofür der Drucker verwendet wird.', 422);
        if (!in_array($name, array_column($this->printerReady()->queues(), 'name'), true)) throw new Problem('Drucker nicht gefunden.', 404);
        $this->assign($name, $kind);
        return $this->view() + ['printers' => $this->printerList(), 'message' => '„' . $name . '“ wird jetzt als ' . ($kind === 'office' ? 'Praxisdrucker (A4)' : 'Bondrucker') . ' verwendet.'];
    }
    private function printerRemove(string $name): array {
        $this->printerReady()->remove($name);
        return ['printers' => $this->printerList(), 'message' => 'Drucker „' . $name . '“ entfernt.'];
    }
    /** Einstellungen auf die Warteschlange umstellen (Bondrucker: Modell und Breite passend). */
    private function assign(string $name, string $kind): void {
        if ($kind === 'office') {
            Settings::save($this->db, $this->base, 'office_printing', ['enabled' => true, 'queue' => $name, 'cups_server' => '']);
        } else {
            Settings::save($this->db, $this->base, 'printing', ['enabled' => true, 'model' => $kind, 'transport' => 'cups', 'queue' => $name, 'cups_server' => '', 'width_dots' => '']);
        }
    }

    /** Einrichtungspaket für Arbeitsplätze als Download; nur mit Admin-Sitzung. */
    public function clientDownload(?string $sessionToken, string $system, callable $header, callable $write, string $certificate = '/etc/kienzle-sumup/server.crt'): void {
        if (!$this->admin->session($sessionToken)) throw new Problem('Bitte zuerst in den Einstellungen anmelden.', 401);
        $package = new ClientPackage($this->config(), dirname(__DIR__), $certificate);
        $files = $package->files($system);
        $size = ClientPackage::zip($files, fn() => null);   // Größe vorab für Content-Length
        $header('Content-Type: application/zip');
        $header('Content-Disposition: attachment; filename="' . ClientPackage::filename($system) . '"');
        $header('Content-Length: ' . $size);
        ClientPackage::zip($files, $write);
    }

    private function clients(): array {
        $out = [];
        foreach (ClientPackage::SYSTEMS as $id => $spec) {
            $bundled = array_values(array_filter($spec['assets'], fn($a) => is_file(dirname(__DIR__) . '/client-dist/' . $a)));
            $out[] = ['id' => $id, 'label' => $spec['label'], 'bundled' => (bool)$bundled];
        }
        return $out;
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
