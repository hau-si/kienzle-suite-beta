'use strict';
const $ = id => document.getElementById(id);
let csrf = '';
const TESTS = {sumup: ['sumup', 'Verbindung zu SumUp prüfen'], printing: ['receipt_printer', 'Testbon drucken'],
  office_printing: ['office_printer', 'Testseite drucken'], invoice_export: ['export', 'Schreibzugriff prüfen']};
// Felder, die nur bei bestimmten Werten sichtbar sind.
const SHOW = {
  'sumup.api_key': v => v.mode === 'live', 'sumup.affiliate_key': v => v.mode === 'live', 'sumup.app_id': v => v.mode === 'live',
  'sumup.merchant_code': v => v.mode === 'live', 'sumup.reader_id': v => v.mode === 'live',
  'fhir.base_url': v => v.mode === 'live', 'fhir.launch_urls': v => v.mode === 'live', 'fhir.api_key': v => v.mode === 'live',
  'fhir.entry_code': v => v.mode === 'live', 'fhir.ca_pem': v => v.mode === 'live', 'fhir.pin_certificate': v => v.mode === 'live',
  'printing.queue': v => v.transport === 'cups', 'printing.cups_server': v => v.transport === 'cups', 'printing.share': v => v.transport === 'samba',
  'mail.password': v => !!v.username, 'invoice_export.password': v => !!v.username, 'invoice_export.domain': v => !!v.username,
};

function el(tag, text, cls, attrs) {
  const node = document.createElement(tag);
  if (text != null) node.textContent = text;
  if (cls) node.className = cls;
  for (const [k, v] of Object.entries(attrs || {})) node.setAttribute(k, v);
  return node;
}
// Hilfe-Symbol mit Erklärung, Beispiel und Bedeutung der Auswahlmöglichkeiten (Maus, Tastatur, Antippen).
function tip(field) {
  if (!field.tip && !field.example && !field.options) return null;
  const box = el('span', null, 'tip-box', {role: 'tooltip'});
  if (field.tip) box.append(el('span', field.tip, 'tip-text'));
  if (field.options && field.choices) for (const [value, text] of Object.entries(field.options)) {
    const line = el('span', null, 'tip-option'); line.append(el('strong', (field.choices[value] || value) + ': '), document.createTextNode(text)); box.append(line);
  }
  if (field.example) { const line = el('span', null, 'tip-example'); line.append(el('strong', 'Beispiel: '), el('code', field.example)); box.append(line); }
  const button = el('button', 'i', 'tip', {type: 'button', 'aria-label': 'Hilfe zu ' + field.label});
  button.onclick = event => { event.preventDefault(); const open = !button.classList.contains('open');
    document.querySelectorAll('.tip.open').forEach(t => t.classList.remove('open')); button.classList.toggle('open', open); };
  button.append(box);
  return button;
}
document.addEventListener('click', event => { if (!event.target.closest('.tip')) document.querySelectorAll('.tip.open').forEach(t => t.classList.remove('open')); });
document.addEventListener('keydown', event => { if (event.key === 'Escape') document.querySelectorAll('.tip.open').forEach(t => t.classList.remove('open')); });

async function api(action, data = {}) {
  const response = await fetch('/settings-api.php', {method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}, body: JSON.stringify({action, ...data})});
  const result = await response.json().catch(() => ({}));
  if (response.status === 401 && !['login', 'setup', 'status'].includes(action)) { show('login'); throw new Error(result.error || 'Bitte anmelden.'); }
  if (!response.ok) throw new Error(result.error || 'Fehler ' + response.status);
  return result;
}
function message(text, error) {
  const m = $('message'); m.hidden = !text; m.textContent = text || ''; m.classList.toggle('error', !!error);
  if (text) window.scrollTo({top: 0, behavior: 'smooth'});
}
function show(view) { for (const id of ['setup', 'login', 'settings']) $(id).hidden = id !== view; }

function values(form, section) {
  const out = {};
  for (const field of section.fields) {
    const input = form.elements[field.key]; if (!input) continue;
    out[field.key] = field.type === 'bool' ? input.checked : input.value;
  }
  return out;
}
function applyVisibility(form, section) {
  const current = values(form, section);
  const enabled = section.fields.some(f => f.key === 'enabled') ? current.enabled : true;
  for (const field of section.fields) {
    const row = form.querySelector('[data-field="' + field.key + '"]');
    const rule = SHOW[section.id + '.' + field.key];
    row.hidden = (field.key !== 'enabled' && !enabled) || (rule ? !rule(current) : false);
  }
}
function render(data) {
  csrf = data.csrf || csrf;
  $('mode').hidden = false;
  $('mode').textContent = data.mode === 'live' ? (data.fhir_mode === 'live' ? 'LIVE' : 'LIVE · T2MED TESTMODUS') : 'TESTMODUS';
  $('mode').classList.toggle('live', data.mode === 'live');
  $('warning').hidden = !data.warning; $('warning').textContent = data.warning || '';
  $('invoice-printer-help').hidden = !data.invoice_printer;
  if (data.clients) renderClients(data.clients);
  const tabs = $('tabs'), root = $('sections'); tabs.replaceChildren(); root.replaceChildren();
  tabs.append(el('a', 'Drucker einrichten', 'tab-strong', {href: '#s-printers'}), el('a', 'Arbeitsplätze einrichten', 'tab-strong', {href: '#s-clients'}));
  for (const section of data.sections) {
    tabs.append(el('a', section.label, null, {href: '#s-' + section.id}));
    const card = el('section', null, 'card settings-card', {id: 's-' + section.id});
    card.append(el('h2', section.label));
    const form = el('form', null, 'settings-form');
    for (const field of section.fields) {
      const row = el('div', null, 'field', {'data-field': field.key});
      const id = 'f-' + section.id + '-' + field.key;
      let input;
      if (field.type === 'bool') {
        const label = el('label', null, 'check'); input = el('input', null, null, {type: 'checkbox', id, name: field.key});
        input.checked = !!field.value; label.append(input, document.createTextNode(' ' + field.label));
        const line = el('div', null, 'label-row'); line.append(label); const t = tip(field); if (t) line.append(t); row.append(line);
      } else {
        const line = el('div', null, 'label-row'); line.append(el('label', field.label, null, {for: id}));
        const t = tip(field); if (t) line.append(t); row.append(line);
        if (field.type === 'choice') {
          input = el('select', null, null, {id, name: field.key});
          for (const [value, label] of Object.entries(field.choices)) { const o = el('option', label, null, {value}); if (value === field.value) o.selected = true; input.append(o); }
        } else if (field.type === 'list' || field.type === 'pem') {
          input = el('textarea', null, null, {id, name: field.key, rows: field.type === 'pem' ? '6' : '3', spellcheck: 'false'});
          if (field.type === 'list') input.value = field.value || '';
          else input.placeholder = field.value.set ? 'Zertifikat gespeichert – leer lassen zum Behalten, „-“ zum Entfernen' : '-----BEGIN CERTIFICATE----- …';
        } else {
          input = el('input', null, null, {id, name: field.key, type: field.type === 'secret' ? 'password' : (field.type === 'int' || field.type === 'money' ? 'number' : 'text'), autocomplete: 'off', spellcheck: 'false'});
          if (field.type === 'secret') input.placeholder = field.value.set ? 'gespeichert – leer lassen zum Behalten' : 'noch nicht gesetzt';
          else input.value = field.value ?? '';
          if (field.type === 'money') { input.min = '1'; input.step = '1'; }
        }
        if (field.example && field.type !== 'secret' && field.type !== 'pem' && field.type !== 'choice') input.placeholder = 'z. B. ' + field.example;
        row.append(input);
        if (field.type === 'choice' && field.options) {
          // Erklärung der gerade gewählten Möglichkeit direkt unter der Auswahl.
          const explain = el('p', null, 'muted small option-help');
          const update = () => { explain.textContent = field.options[input.value] || ''; };
          input.addEventListener('change', update); update(); row.append(explain);
        }
      }
      if (field.help) row.append(el('p', field.help, 'muted small'));
      form.append(row);
    }
    const buttons = el('div', null, 'row-buttons');
    const save = el('button', 'Speichern', 'primary', {type: 'submit'}); buttons.append(save);
    if (TESTS[section.id]) {
      const [kind, label] = TESTS[section.id]; const test = el('button', label, 'secondary', {type: 'button'});
      test.onclick = () => busy(test, async () => { message((await api('test', {kind})).message); });
      buttons.append(test);
    }
    form.append(buttons);
    form.oninput = form.onchange = event => {
      // Anderes Druckermodell: Breite leeren, der Server setzt die passende Breite.
      if (section.id === 'printing' && event.target.name === 'model' && form.elements.width_dots) form.elements.width_dots.value = '';
      applyVisibility(form, section);
    };
    form.onsubmit = event => { event.preventDefault(); busy(save, async () => {
      const result = await api('save', {section: section.id, values: values(form, section)});
      render(result); message(result.message); location.hash = 's-' + section.id;
    }); };
    card.append(form); root.append(card);
    applyVisibility(form, section);
  }
}
async function busy(button, action) {
  button.disabled = true;
  try { await action(); } catch (error) { message(error.message, true); } finally { button.disabled = false; }
}
async function loadSettings() { render(await api('get')); show('settings'); loadPrinters(); }

$('setup-form').onsubmit = event => { event.preventDefault(); busy(event.submitter, async () => {
  if ($('setup-password').value !== $('setup-password2').value) throw new Error('Die Passwörter stimmen nicht überein.');
  const token = new URLSearchParams(location.hash.slice(1)).get('setup');
  const result = await api('setup', {token, password: $('setup-password').value});
  csrf = result.csrf; history.replaceState(null, '', '/settings.php'); message('Admin-Passwort festgelegt. Jetzt die Einstellungen eintragen.');
  await loadSettings();
}); };
$('login-form').onsubmit = event => { event.preventDefault(); busy(event.submitter, async () => {
  const result = await api('login', {password: $('login-password').value}); csrf = result.csrf; $('login-password').value = ''; message(''); await loadSettings();
}); };
$('password-form').onsubmit = event => { event.preventDefault(); busy(event.submitter, async () => {
  const result = await api('password', {current: $('pw-current').value, new: $('pw-new').value});
  csrf = result.csrf; $('password-form').reset(); message(result.message);
}); };
$('logout').onclick = async () => { await api('logout').catch(() => {}); csrf = ''; show('login'); };
$('printer-setup').onclick = () => busy($('printer-setup'), async () => {
  const data = await api('printer_setup');
  const link = el('a', null, null, {href: URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], {type: 'application/json'})), download: 'kienzle-sumup-drucker.json'});
  document.body.append(link); link.click(); link.remove();
  message('Einrichtungsdatei heruntergeladen. Sie enthält den Rechnungseingangs-Schlüssel – nur auf den Druckserver kopieren und danach löschen.');
});

// ---- Drucker einrichten ---------------------------------------------------------------
const PRINTER_FIELDS = {
  kind: {label: 'Art des Druckers', tip: 'Wofür der Drucker verwendet wird. Bondrucker drucken Belege, der A4-Drucker Rechnungen.',
    options: {'tm-m10': 'Schmaler Bondrucker (58 mm) mit Netzwerk/WLAN oder USB.', 'tm-m30iii': 'Breiter Bondrucker (80 mm). Im Webmenü des Druckers „Sicherer Druck“ ausschalten.',
      office: 'Normaler Bürodrucker, z. B. Brother, HP, Canon, Epson, Kyocera. Er muss IPP bzw. AirPrint können (fast alle Netzwerkdrucker seit 2015).'}},
  address: {label: 'IP-Adresse oder Rechnername des Druckers', example: '192.168.1.80',
    tip: 'Steht beim Bondrucker auf dem Statusbon (Einschalten mit gedrückter Vorschubtaste), beim A4-Drucker im Druckermenü unter Netzwerk. Einfacher: oben „suchen“.'},
  name: {label: 'Name (optional)', example: 'TM-m30III', tip: 'Wie der Drucker hier heißen soll. Leer lassen für den Vorschlag. Nur Buchstaben, Ziffern, - und _.'},
};
let printerKinds = {};
function printerRole(queue) { return queue.role === 'receipt' ? 'Bondrucker' : queue.role === 'office' ? 'Praxisdrucker (A4)' : ''; }
function renderPrinters(data) {
  printerKinds = data.kinds || printerKinds;
  $('printers-unavailable').hidden = data.available; $('printers-body').hidden = !data.available;
  const list = $('printer-list'); list.replaceChildren();
  if (!data.queues.length) list.append(el('p', 'Noch kein Drucker eingerichtet.', 'muted'));
  for (const q of data.queues) {
    const row = el('div', null, 'manage-row printer-row');
    const text = el('span'); text.append(el('strong', q.name), document.createTextNode(' · ' + q.address + (q.state ? ' · ' + q.state : '')));
    if (q.role) text.append(el('em', '  – verwendet als ' + printerRole(q), 'role'));
    row.append(text);
    const kind = el('select', null, 'compact', {'aria-label': 'Verwenden als'});
    for (const [id, k] of Object.entries(printerKinds)) kind.append(el('option', 'als ' + k.label.replace(' im Netzwerk (IPP / AirPrint)', ''), null, {value: id}));
    kind.value = q.role === 'office' ? 'office' : (/m30/i.test(q.name + q.description) ? 'tm-m30iii' : (q.role === 'receipt' || /tm|bon/i.test(q.name) ? 'tm-m10' : 'office'));
    const use = el('button', 'Verwenden', 'secondary', {type: 'button'});
    use.onclick = () => busy(use, async () => { const r = await api('printer_use', {name: q.name, kind: kind.value}); render(r); renderPrinters(r.printers); message(r.message); });
    const test = el('button', 'Testdruck', 'secondary', {type: 'button'}); test.hidden = !q.role;
    test.onclick = () => busy(test, async () => { message((await api('test', {kind: q.role === 'office' ? 'office_printer' : 'receipt_printer'})).message); });
    row.append(kind, use, test);
    if (q.managed) {
      const remove = el('button', 'Entfernen', 'text-button danger', {type: 'button'});
      remove.onclick = () => busy(remove, async () => { const r = await api('printer_remove', {name: q.name}); renderPrinters(r.printers); message(r.message); });
      row.append(remove);
    }
    list.append(row);
  }
}
function printerForm(prefill = {}) {
  const form = $('printer-form'); form.replaceChildren();
  for (const [key, field] of Object.entries(PRINTER_FIELDS)) {
    const row = el('div', null, 'field'); const id = 'p-' + key;
    const line = el('div', null, 'label-row'); line.append(el('label', field.label, null, {for: id}));
    const choices = key === 'kind' ? Object.fromEntries(Object.entries(printerKinds).map(([k, v]) => [k, v.label])) : null;
    const t = tip({...field, choices}); if (t) line.append(t); row.append(line);
    let input;
    if (key === 'kind') {
      input = el('select', null, null, {id, name: key});
      for (const [value, label] of Object.entries(choices)) input.append(el('option', label, null, {value}));
      const explain = el('p', null, 'muted small option-help');
      const update = () => { explain.textContent = field.options[input.value] || ''; const n = form.elements.name; if (n && !n.dataset.touched) n.placeholder = 'Vorschlag: ' + (printerKinds[input.value] || {}).name; };
      input.addEventListener('change', update); row.append(input, explain); setTimeout(update);
    } else {
      input = el('input', null, null, {id, name: key, type: 'text', autocomplete: 'off', spellcheck: 'false', placeholder: 'z. B. ' + field.example});
      if (key === 'name') input.oninput = () => { input.dataset.touched = '1'; };
      row.append(input);
    }
    if (prefill[key]) input.value = prefill[key];
    form.append(row);
  }
  const buttons = el('div', null, 'row-buttons'); const add = el('button', 'Drucker einrichten', 'primary', {type: 'submit'}); buttons.append(add); form.append(buttons);
  form.onsubmit = event => { event.preventDefault(); busy(add, async () => {
    const r = await api('printer_add', {kind: form.elements.kind.value, address: form.elements.address.value, name: form.elements.name.value});
    render(r); renderPrinters(r.printers); $('printer-found').replaceChildren(); printerForm(); message(r.message); location.hash = 's-printers';
  }); };
}
$('printer-search').onclick = () => busy($('printer-search'), async () => {
  const box = $('printer-found'); box.replaceChildren(el('p', 'Suche läuft (bis zu 30 Sekunden) …', 'muted'));
  try {
    const {found} = await api('printer_discover');
    box.replaceChildren();
    if (!found.length) { box.append(el('p', 'Keine Drucker gefunden. Ist der Drucker eingeschaltet und im selben Netzwerk? Sonst unten die IP-Adresse eintragen.', 'muted')); return; }
    box.append(el('p', 'Gefunden – „Auswählen“ übernimmt den Drucker ins Formular:', 'muted small'));
    for (const d of found) {
      const row = el('div', null, 'manage-row'); const text = el('span'); text.append(el('strong', d.name), document.createTextNode(' · ' + d.connection));
      const pick = el('button', 'Auswählen', 'secondary', {type: 'button'});
      pick.onclick = () => { printerForm({kind: d.kind || 'office', address: d.uri}); $('printer-form').elements.kind.dispatchEvent(new Event('change')); $('printer-form').scrollIntoView({behavior: 'smooth', block: 'center'}); };
      row.append(text, pick); box.append(row);
    }
  } catch (error) { box.replaceChildren(); throw error; }
});

// ---- Arbeitsplätze ---------------------------------------------------------------------
const CLIENT_STEPS = {
  windows: ['ZIP-Datei mit Rechtsklick → „Alle extrahieren …“ entpacken.', '„install-windows.cmd“ doppelklicken. Bei „Windows hat den PC geschützt“: „Weitere Informationen“ → „Trotzdem ausführen“.',
    'Die Zertifikatswarnung mit „Ja“ bestätigen – das Fenster liegt manchmal hinter anderen Fenstern (Taskleiste prüfen).', 'Fertig, wenn „Kienzle-SumUp eingerichtet“ erscheint.'],
  macos: ['ZIP-Datei doppelklicken.', '„install-macos.command“ mit Rechtsklick → „Öffnen“ starten.', 'Mac-Passwort für das Zertifikat eingeben.'],
  linux: ['ZIP-Datei entpacken.', 'Im entpackten Ordner im Terminal: bash install-linux.sh', 'server.crt im Browser als vertrauenswürdig importieren.'],
};
function guessSystem() { const p = navigator.userAgent; return /Windows/.test(p) ? 'windows' : /Mac OS X|Macintosh/.test(p) ? 'macos' : /Linux|X11/.test(p) ? 'linux' : 'windows'; }
function renderClients(clients) {
  const box = $('client-downloads'); box.replaceChildren(); const steps = $('client-steps'); steps.replaceChildren();
  const mine = guessSystem();
  for (const c of clients || []) {
    const link = el('a', 'Paket für ' + c.label + ' laden', c.id === mine ? 'button primary' : 'button secondary', {href: '/client-download.php?os=' + c.id, download: ''});
    link.onclick = () => { renderSteps(c.id); message('Paket für ' + c.label + ' wird geladen. Anleitung steht unten und in LIESMICH.txt.'); };
    box.append(link);
  }
  const t = tip({label: 'Arbeitsplätze', tip: 'Jeder Arbeitsplatz, an dem aus T2med kassiert wird, braucht den Starter einmal. Danach öffnet der Knopf in T2med die Kartenzahlung.' }); if (t) box.append(t);
  renderSteps(mine);
  function renderSteps(id) {
    steps.replaceChildren(el('strong', 'So geht es unter ' + (clients.find(c => c.id === id) || {}).label + ':'));
    const ol = el('ol'); for (const s of CLIENT_STEPS[id]) ol.append(el('li', s)); steps.append(ol);
    const c = clients.find(x => x.id === id);
    if (c && !c.bundled) steps.append(el('p', 'Hinweis: Der Server hat die Starterprogramme nicht vorrätig; der Arbeitsplatz lädt sie bei der Einrichtung von GitHub (Internet nötig).', 'muted small'));
  }
}
async function loadPrinters() { try { renderPrinters(await api('printers')); } catch (error) { message(error.message, true); } printerForm(); }

(async () => {
  try {
    const status = await api('status');
    csrf = status.csrf || '';
    if (status.setup_required) {
      show('setup');
      const hasToken = new URLSearchParams(location.hash.slice(1)).get('setup');
      $('setup-form').hidden = !hasToken; $('setup-missing').hidden = !!hasToken;
    } else if (status.logged_in) await loadSettings();
    else show('login');
  } catch (error) { message(error.message, true); }
})();
