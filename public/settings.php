<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
$version = htmlspecialchars(trim(file_get_contents(dirname(__DIR__) . '/VERSION')), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kienzle-SumUp · Einstellungen</title><link rel="stylesheet" href="/assets/style.css?v=<?= $version ?>"><script src="/assets/settings.js?v=<?= $version ?>" defer></script></head>
<body><main class="settings-main">
<header class="top"><div class="brand"><span class="mark" aria-hidden="true">K</span><div><strong>kienzle-sumup</strong><span>Einstellungen</span></div></div><span id="mode" class="badge" hidden></span></header>
<p id="message" class="notice" role="status" hidden></p>

<section id="setup" class="card" hidden><h1>Ersteinrichtung</h1>
<p>Lege das Admin-Passwort für diese Einstellungsseite fest. Arbeitsplätze kassieren weiterhin ohne Passwort.</p>
<form id="setup-form"><label>Admin-Passwort (mind. 10 Zeichen)<input id="setup-password" type="password" autocomplete="new-password" minlength="10" required></label>
<label>Passwort wiederholen<input id="setup-password2" type="password" autocomplete="new-password" minlength="10" required></label>
<button class="primary wide" type="submit">Passwort festlegen</button></form>
<p id="setup-missing" class="inline-error" hidden>Dieser Link ist unvollständig. Bitte den Einrichtungslink aus dem Installer verwenden oder einen neuen erzeugen: <code>sudo bash scripts/install-server.sh --admin-reset</code></p></section>

<section id="login" class="card" hidden><h1>Anmelden</h1>
<form id="login-form"><label>Admin-Passwort<input id="login-password" type="password" autocomplete="current-password" required></label>
<button class="primary wide" type="submit">Anmelden</button></form>
<p class="muted small">Passwort vergessen? Auf dem Server <code>sudo bash scripts/install-server.sh --admin-reset</code> ausführen.</p></section>

<div id="settings" hidden>
<nav id="tabs" class="settings-tabs" aria-label="Bereiche"></nav>
<p id="warning" class="inline-error" hidden></p>
<div id="sections"></div>
<section class="card settings-card" id="s-printers"><h2>Drucker einrichten</h2>
<p class="muted">Bondrucker (Epson TM-m10, TM-m30III) und A4-Drucker im Netzwerk oder per USB an diesem Server einrichten – ohne Terminal.</p>
<div id="printers-unavailable" class="inline-error" hidden>Der Druckdienst fehlt auf diesem Server. Bitte einmal <code>sudo bash scripts/install-server.sh</code> ausführen.</div>
<div id="printers-body">
<h3>Eingerichtete Drucker</h3><div id="printer-list"></div>
<h3>Neuen Drucker hinzufügen</h3>
<div class="row-buttons"><button id="printer-search" class="secondary" type="button">Drucker im Netzwerk und per USB suchen</button></div>
<div id="printer-found"></div>
<form id="printer-form" class="settings-form"></form>
</div></section>
<section class="card settings-card" id="s-t2med-paid"><h2>T2med-Rechnungen als bezahlt abhaken</h2>
<p>Alle über den Drucker „SumUp-Rechnung“ übernommenen und per Karte bezahlten T2med-Rechnungen, nach Rechnungsnummer sortiert. In T2med unter Rechnungen die Nummern als bezahlt markieren und hier anschließend abhaken – dann erscheinen beim nächsten Mal nur die neuen.</p>
<div id="t2med-paid" class="settings-form"></div></section>
<section class="card settings-card" id="s-export"><h2>Export für die Steuerberatung</h2>
<p>Alle hier im Rechnungstool erstellten und per Karte bezahlten Rechnungen als Excel-Tabelle – für ein ganzes Jahr oder einen Monat. T2med-Rechnungen sind nicht enthalten; sie stehen in T2med.</p>
<form id="export-form" class="settings-form export-form"></form>
<p class="muted small">Die Datei hat drei Blätter: <strong>Rechnungen</strong> (je Rechnung Brutto, Netto und MwSt. je Satz), <strong>Positionen</strong> (jede Leistung einzeln) und <strong>Monatssummen</strong>. Patientennamen nur, wenn angekreuzt.</p></section>
<section class="card settings-card" id="s-clients"><h2>Arbeitsplätze einrichten</h2>
<p>Auf dem Arbeitsplatz diese Seite öffnen, anmelden und das passende Paket laden. Es enthält den Starter, mit dem T2med die Kartenzahlung öffnet.</p>
<div id="client-downloads" class="row-buttons"></div>
<div id="client-steps" class="steps"></div>
<p class="muted small">Die Pakete enthalten den Starter-Schlüssel der Praxis: nicht per E-Mail verschicken, nach der Einrichtung löschen.</p></section>
<section class="card" id="invoice-printer-help" hidden><h2>Drucker „SumUp-Rechnung“ einrichten</h2>
<p>Auf dem Server, auf dem T2med-Rechnungen gedruckt werden sollen (meist dieser Server), im Projektordner ausführen:</p>
<pre class="code">sudo bash scripts/install-invoice-printer.sh</pre>
<p class="muted small">Läuft der Druckserver auf einem anderen Rechner: Einrichtungsdatei herunterladen, geschützt dorthin kopieren und <code>sudo bash scripts/install-invoice-printer.sh /pfad/kienzle-sumup-drucker.json</code> ausführen.</p>
<button id="printer-setup" class="secondary" type="button">Einrichtungsdatei herunterladen</button></section>
<section class="card"><h2>Admin-Passwort ändern</h2>
<form id="password-form"><label>Bisheriges Passwort<input id="pw-current" type="password" autocomplete="current-password" required></label>
<label>Neues Passwort (mind. 10 Zeichen)<input id="pw-new" type="password" autocomplete="new-password" minlength="10" required></label>
<button class="secondary" type="submit">Passwort ändern</button></form></section>
<p class="settings-foot"><a href="/">Zur Kartenzahlung</a> · <button id="logout" class="text-button" type="button">Abmelden</button></p>
</div>
<footer>kienzle-sumup v<?= $version ?></footer>
</main></body></html>
