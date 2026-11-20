<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Firewall aus dem Webinterface. Die Anwendung wählt nur Netze und Optionen; die Regeln erzeugt und lädt der
 * root-Dienst kienzle-sumup-firewall (scripts/firewall-apply.py) aus festen Vorlagen. Schutz vor Aussperren:
 * das anfragende Gerät muss in einem freigegebenen Netz liegen, und ohne Bestätigung binnen zwei Minuten
 * stellt der Dienst die vorherigen Regeln wieder her.
 */
final class Firewall {
    public static string $state = '/var/lib/kienzle-sumup-firewall';
    public static string $service = '/usr/local/sbin/kienzle-sumup-firewall';
    private const KEY = 'firewall_config';

    public function __construct(private Database $db, private Network $network) {}

    public function overview(string $client): array {
        $client = Network::clean($client);
        $net = $this->network->detect();
        $config = $this->config();
        $covering = null;
        foreach ($net['interfaces'] as $i) foreach ($i['addresses'] as $a) if (Network::contains($a['network'], $client)) $covering = $a['network'];
        if ($config === null) {
            // Vorschlag: das Netz, aus dem gerade zugegriffen wird; sonst alle erkannten privaten Netze.
            $suggest = [];
            if ($covering && Network::isPrivate($covering)) $suggest = [$covering];
            else foreach ($net['interfaces'] as $i) foreach ($i['addresses'] as $a) if ($a['private'] && $a['family'] === 4 && !in_array($a['network'], $suggest, true)) $suggest[] = $a['network'];
            $config = ['cidrs' => $suggest, 'ssh' => true, 'ipp' => true, 'strict' => false];
        }
        return ['network' => $net, 'client' => ['ip' => $client, 'network' => $covering, 'loopback' => Network::isLoopback($client)],
            'config' => $config, 'saved' => $this->config() !== null, 'status' => $this->status(), 'service' => is_file(self::$service)];
    }

    /** Eingabe prüfen und bereinigen; wirft Problem, wenn das Gerät des Administrators ausgesperrt würde. */
    public function validate(array $input, string $client): array {
        $client = Network::clean($client);
        $list = [];
        foreach ((array)($input['networks'] ?? []) as $n) if (is_string($n)) $list[] = $n;
        foreach (preg_split('/[\s,;]+/', (string)($input['extra'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $n) $list[] = $n;
        $cidrs = [];
        foreach ($list as $raw) {
            $net = Network::normalize((string)$raw);
            if ($net === null) throw new Problem('Das Netz „' . mb_substr(preg_replace('/[^0-9a-fA-F:.\/]/', '', (string)$raw), 0, 50) . '“ ist ungültig. Beispiel: 192.168.1.0/24', 422);
            if (!Network::isPrivate($net)) throw new Problem('Nur private Netze sind erlaubt (10.x.x.x, 172.16–31.x.x, 192.168.x.x, 100.64–127.x.x). „' . $net . '“ gehört nicht dazu.', 422);
            if (!in_array($net, $cidrs, true)) $cidrs[] = $net;
        }
        if (!$cidrs) throw new Problem('Bitte mindestens ein Netz freigeben, sonst käme niemand mehr auf die Seite.', 422);
        if (count($cidrs) > 20) throw new Problem('Höchstens 20 Netze.', 422);
        if (!Network::isLoopback($client)) {
            $inside = false;
            foreach ($cidrs as $c) if (Network::contains($c, $client)) $inside = true;
            if (!$inside) throw new Problem('Dein jetziges Gerät (' . $client . ') läge außerhalb der freigegebenen Netze und wäre ausgesperrt. Bitte sein Netz mit freigeben.', 422);
        }
        return ['cidrs' => $cidrs, 'ssh' => ($input['ssh'] ?? false) === true, 'ipp' => ($input['ipp'] ?? false) === true, 'strict' => ($input['strict'] ?? false) === true];
    }

    public function apply(array $input, string $client): array {
        $config = $this->validate($input, $client);
        if (!is_file(self::$service)) throw new Problem('Der Firewall-Dienst fehlt. Bitte einmal den aktuellen Server-Installer ausführen (oder in den Einstellungen aktualisieren).', 503);
        if (in_array($this->status()['state'], ['pending', 'requested'], true)) throw new Problem('Eine Firewall-Änderung wartet noch auf Bestätigung.', 409);
        $this->save($config);
        @unlink($this->db->directory . '/firewall-confirm');
        $this->write(['action' => 'apply'] + $config);
        return ['message' => 'Firewall wird geladen. Bitte gleich bestätigen, dass diese Seite noch funktioniert.'];
    }

    public function disable(): array {
        if (!is_file(self::$service)) throw new Problem('Der Firewall-Dienst fehlt.', 503);
        $this->write(['action' => 'disable']);
        return ['message' => 'Firewall wird ausgeschaltet.'];
    }

    /** Bestätigung, dass die Seite nach dem Laden erreichbar war: erst danach bleibt die Firewall dauerhaft. */
    public function confirm(): array {
        $status = $this->status();
        if ($status['state'] !== 'pending') throw new Problem('Es gibt nichts zu bestätigen.', 409);
        $path = $this->db->directory . '/firewall-confirm';
        if (file_put_contents($path, (string)time()) === false) throw new Problem('Bestätigung konnte nicht gespeichert werden.', 500);
        return ['message' => 'Bestätigt. Die Firewall wird dauerhaft übernommen.'];
    }

    public function status(): array {
        $s = json_decode((string)@file_get_contents(self::$state . '/status.json'), true);
        $s = is_array($s) ? $s : [];
        $requested = is_file($this->db->directory . '/firewall-request');
        $state = (string)($s['state'] ?? 'off');
        if ($requested && $state !== 'pending') $state = 'requested';
        if ($state === 'pending' && ($s['deadline'] ?? 0) < time() - 5) $state = 'reverted';
        return ['state' => $state, 'message' => (string)($s['message'] ?? ''), 'deadline' => $s['deadline'] ?? null, 'networks' => $s['networks'] ?? [],
            'ports' => $s['ports'] ?? [], 'ssh' => $s['ssh'] ?? false, 'ipp' => $s['ipp'] ?? false, 'strict' => $s['strict'] ?? false, 'now' => time()];
    }

    private function config(): ?array {
        $row = $this->db->one('SELECT value FROM settings WHERE key=?', [self::KEY]);
        $c = $row ? json_decode($row['value'], true) : null;
        return is_array($c) && isset($c['cidrs']) ? $c : null;
    }
    private function save(array $config): void {
        $this->db->query('INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at', [self::KEY, json_encode($config), time()]);
    }
    private function write(array $request): void {
        $path = $this->db->directory . '/firewall-request';
        $tmp = $path . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, json_encode($request, JSON_THROW_ON_ERROR)) === false || !rename($tmp, $path)) { @unlink($tmp); throw new Problem('Anfrage konnte nicht gespeichert werden.', 500); }
    }
}
