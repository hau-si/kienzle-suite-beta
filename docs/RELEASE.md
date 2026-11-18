Version 1.4.1-rechnung.1 der Kartenzahlungsseite für T2med und SumUp Solo (Fork).

- Neu: T2med-Rechnung kassieren. Eine auf den virtuellen Drucker „SumUp-Rechnung“
  gedruckte Liquidation erscheint beim passenden Patienten als offene Rechnung und wird
  mit einem Klick am Solo kassiert. Rechnungsnummer als SumUp-Beschreibung, keine
  Patientendaten an SumUp, Akteneintrag mit Rechnungsbezug.
- Rechnungseingang `/invoice-inbox.php` mit eigenem Schlüssel; PDF bis 5 MB.
- Strikte Prüfung von Rechnungsnummer, Datum, Gesamtsumme, Zahlbetrag, Patient und
  Geburtsdatum. Schutz vor Doppelzahlung derselben Rechnungsnummer.
- Druckserver-Installer `scripts/install-invoice-printer.sh`, CUPS-Backend und
  Mac-PDF-Treiber `macos/SumUp-Rechnung-PDF.ppd`.
- Datenbank-Version 5 (neue Tabelle `invoice_inbox`); bestehende Daten bleiben erhalten.
- Starter für die Arbeitsplätze unverändert; die Client-Installer laden weiterhin `v1.4.1`.

Update auf dem Anwendungsserver:

```bash
git pull --ff-only
sudo bash scripts/install-server.sh
```

Die neue Frage **T2med-Rechnungen über virtuellen Drucker „SumUp-Rechnung“ übernehmen**
mit `ja` beantworten und danach auf dem Druckserver
`sudo bash scripts/install-invoice-printer.sh /root/kienzle-sumup-printer` ausführen.
