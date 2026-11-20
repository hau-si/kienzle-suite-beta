Version 1.4.1-rechnung.14 der Kartenzahlungsseite für T2med und SumUp Solo (Fork).

- Neu: T2med-Rechnung kassieren. Eine auf den virtuellen Drucker „SumUp-Rechnung“
  gedruckte Liquidation erscheint beim passenden Patienten als offene Rechnung und wird
  mit einem Klick am Solo kassiert. Rechnungsnummer als SumUp-Beschreibung, keine
  Patientendaten an SumUp, Akteneintrag mit Rechnungsbezug.
- Neu in rechnung.14: Härtung: Sperre bei Fehlversuchen je Absender statt für alle, Gesamtbremse, Sitzungen
  höchstens 12 Stunden, schwache Passwörter abgelehnt, Drosselung falscher Starter- und Druckserver-Schlüssel,
  Sicherheitsprotokoll in den Einstellungen, Anfragen anderer Websites abgewiesen, zusätzliche Schutz-Header,
  Webserver gegen langsame Verbindungen, strengere TLS-Verfahren, Systemd-Sandbox für Webserver und PHP,
  gefährliche PHP-Funktionen abgeschaltet, Größenbegrenzung beim Update-Download.
- Neu in rechnung.13: Aktualisierung im Webinterface: neue Version auf GitHub erkennen (Hinweis oben,
  Neuerungen aus RELEASE.md), mit einem Klick installieren. Ein root-Dienst lädt den eingestellten
  Zweig, sichert Programm und Datenbank, führt den Installer aus und stellt bei Fehlern den vorherigen
  Stand wieder her. Quelle in `/etc/kienzle-sumup/update.conf`. „Ohne Terminzettel“ bleibt bei
  Aktualisierungen erhalten.
- Neu in rechnung.12: Drucker „SumUp-Rechnung“ ohne `sudo`: Der Server-Installer legt Backend und PPD
  ab, die Einstellungsseite legt die Warteschlange beim Einschalten von „T2med-Rechnungen übernehmen“
  an bzw. entfernt sie und schreibt die Verbindungsdaten (`/etc/kienzle-sumup-printer/web`). Das
  Backend liest diese nur als echte Datei (kein Symlink). `install-invoice-printer.sh` bleibt für
  entfernte Druckserver.
- Neu in rechnung.11: Einstellungsseite in Reitern – Abrechnung (laufend), Praxis & Rechnungen,
  Drucker & Bons, Einrichtung (einmalig, mit Überblick der offenen Schritte). Zähler an den Reitern,
  Verweise springen in den passenden Reiter.
- Neu in rechnung.10: Zahlungsart Bar oder Karte im Rechnungstool (Karte vorausgewählt, ein Klick für
  Bar). Barzahlungen ohne Terminal, sofort bezahlt, eigene Rechnungsnummer `BAR-JJJJ-NNNN`, Beleg und
  Akteneintrag als Barzahlung. Export kennzeichnet „Bar“ bzw. „Kartenzahlung“, Monatssummen mit
  „davon bar“/„davon Kartenzahlung“. Datenbank-Version 12.
- Neu in rechnung.9: GOÄ-Rechner beim Anlegen von Leistungen (Einfachsatz + gewünschter Endbetrag →
  nächstliegender Faktor mit zwei Nachkommastellen, Preis = Einfachsatz × Faktor; Hinweise zu
  Schwellenwert und Höchstsatz; Serverprüfung). Liste aller bezahlten T2med-Rechnungen zum Abhaken in
  T2med (nach Rechnungsnummer, „abgehakt“ merken, Excel-Liste). Datenbank-Version 11.
- Neu in rechnung.8: Mehrwertsteuer je Leistung („mit MwSt.“, Preis bleibt Endpreis), Satz und
  Kleinunternehmerregelung (§ 19 UStG) unter Einstellungen → Umsatzsteuer, Steuernummer/USt-IdNr. und
  Hinweistexte auf der Rechnung, Netto/MwSt. je Satz unter der Summe. Der Satz wird je Zahlung
  festgehalten. Excel-Export (XLSX, ohne Zusatzpakete) aller Rechnungen aus dem Rechnungstool je Jahr
  oder Monat mit Blättern Rechnungen, Positionen und Monatssummen. Datenbank-Version 10.
- Neu in rechnung.7: T2med-Terminzettel integriert (aus thomaskien/t2med-terminzettel 1.3.1,
  Ordner `terminzettel/`), vom Server-Installer ohne Rückfragen eingerichtet. Einstellungsbereich
  „Terminzettel“: Schriftgröße getrennt für Starttext, Überschrift, Termine und Endtext, Position
  (rechts/links/unter dem Termin) und Größe des Kalender-QR, Live-Vorschau des Bons aus denselben
  ESC/POS-Daten, Testdruck. Bondrucker und Breite (TM-m10 420, TM-m30III 576 Punkte) kommen
  automatisch aus den Druckereinstellungen. Ohne Webeinstellungen druckt der Terminzettel
  byte-identisch wie bisher.
- Neu in rechnung.6: Info-Symbol mit Erklärung und Beispielwert zu jeder Einstellung und jeder
  Auswahlmöglichkeit. „Drucker einrichten“ im Browser: TM-m10, TM-m30III und A4-Netzwerkdrucker
  (IPP Everywhere) suchen, als CUPS-Warteschlange anlegen, automatisch übernehmen, testen und
  entfernen; der Installer ergänzt dafür `cups` und die Gruppe `lpadmin`. „Arbeitsplätze
  einrichten“: Installationspaket (ZIP) für Windows, macOS und Linux zum Herunterladen, mit den
  vom Server geladenen und geprüften Starterprogrammen.
- Neu in rechnung.5: Einstellungen im Browser (`/settings.php`, Link „Einstellungen“ auf der
  Kartenzahlungsseite), geschützt durch ein Admin-Passwort. Der Server-Installer stellt keine
  Fragen mehr (alte Abfragen mit `--fragen`), installiert alle Zusatzpakete und gibt einen
  einmaligen Einrichtungslink aus; `--admin-reset` erzeugt einen neuen. SumUp, T2med,
  Praxisdaten, E-Mail, Bon- und Praxisdrucker, Rechnungseingang und Netzwerkablage werden dort
  eingetragen, mit Verbindungs- und Testdruck-Knöpfen. Werte verschlüsselt in der Datenbank,
  Geheimnisse werden nie angezeigt. `install-invoice-printer.sh` holt die Daten ohne Angabe vom
  lokalen Server oder aus der heruntergeladenen Einrichtungsdatei. Datenbank-Version 9.
- Neu in rechnung.4: Häkchen „Rechnung nach der Zahlung drucken“ (praxisweit voreinstellbar,
  pro Zahlung abwählbar). T2med-Rechnungen auf dem Praxisdrucker (A4, CUPS), App-Rechnungen
  wahlweise A4 oder Bon. Bondrucker-Modell Epson TM-m30III (80 mm, 576 Punkte) und Anbindung
  über eine CUPS-RAW-Warteschlange als Alternative zu Samba. Datenbank-Version 8.
- Neu in rechnung.3: Auch die in der App erzeugten Rechnungen/Leistungsbelege werden nach
  erfolgreicher Zahlung automatisch im Netzwerkordner abgelegt (Installer-Abfrage,
  `invoice_export.app_invoices`), auch ohne Rechnungseingang. Stündlicher Timer
  `kienzle-sumup-export.timer` holt fehlgeschlagene Ablagen nach; `TEST_`-Präfix im
  Testmodus. Datenbank-Version 7.
- Neu in rechnung.2: Original-PDF der Rechnung optional per SMB in einen Netzwerkordner
  kopieren (z. B. für die Steuerberatung), wahlweise nur bezahlte (`paid`) oder jede
  gedruckte Rechnung (`received`), mit Unterordnern `{jahr}/{monat}`. Datenbank-Version 6.
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
mit `ja` beantworten, optional die Ablage im Netzwerkordner einrichten und danach auf dem Druckserver
`sudo bash scripts/install-invoice-printer.sh /root/kienzle-sumup-printer` ausführen.
