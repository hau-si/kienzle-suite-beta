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
  const tabs = $('tabs'), root = $('sections'); tabs.replaceChildren(); root.replaceChildren();
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
        input.checked = !!field.value; label.append(input, document.createTextNode(' ' + field.label)); row.append(label);
      } else {
        row.append(el('label', field.label, null, {for: id}));
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
        row.append(input);
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
async function loadSettings() { render(await api('get')); show('settings'); }

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
