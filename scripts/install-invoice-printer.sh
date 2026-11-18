#!/usr/bin/env bash
# Richtet auf dem Druckserver (z. B. kienzlebox) den virtuellen Drucker „SumUp-Rechnung“ ein.
# Aufruf: sudo bash scripts/install-invoice-printer.sh [Ordner mit invoice-printer.json und server.crt]
# Standardordner: /root/kienzle-sumup-printer (vom Server-Installer erzeugt).
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
source_dir=$PWD
queue=SumUp-Rechnung
if [ "$EUID" -ne 0 ]; then echo 'Bitte mit sudo ausführen: sudo bash scripts/install-invoice-printer.sh'; exit 1; fi
if [ ! -f /etc/debian_version ]; then echo 'Dieser Installer unterstützt Debian, Ubuntu und Raspberry Pi OS.'; exit 1; fi

if [ "${1:-}" = "--uninstall" ]; then
  lpadmin -x "$queue" 2>/dev/null || true
  rm -f /usr/lib/cups/backend/kienzle-sumup /usr/share/ppd/kienzle-sumup/sumup-rechnung.ppd
  rm -rf /etc/kienzle-sumup-printer
  echo "Drucker $queue entfernt."
  exit 0
fi

setup=${1:-/root/kienzle-sumup-printer}
if [ ! -f "$setup/invoice-printer.json" ] || [ ! -f "$setup/server.crt" ]; then
  echo "In $setup fehlen invoice-printer.json und/oder server.crt."
  echo 'Auf dem Anwendungsserver den Server-Installer mit aktiviertem Rechnungseingang ausführen'
  echo 'und den Ordner /root/kienzle-sumup-printer geschützt auf diesen Druckserver kopieren.'
  exit 1
fi
if grep -q 'PRIVATE KEY' "$setup/server.crt"; then echo 'server.crt darf keinen privaten Schlüssel enthalten.'; exit 1; fi

DEBIAN_FRONTEND=noninteractive apt-get install -y cups python3 ghostscript

# Eine fremde Warteschlange gleichen Namens nicht überschreiben.
if lpstat -v "$queue" >/dev/null 2>&1 && ! lpstat -v "$queue" | grep -q 'kienzle-sumup:/'; then
  echo "Eine fremde Warteschlange $queue existiert bereits; keine Änderung."; exit 1
fi

umask 077
install -d -m 700 -o root -g root /etc/kienzle-sumup-printer
install -m 600 -o root -g root "$setup/invoice-printer.json" /etc/kienzle-sumup-printer/printer.json
install -m 644 -o root -g root "$setup/server.crt" /etc/kienzle-sumup-printer/server.crt
# 0700 root: CUPS startet das Backend als root; es liest Schlüssel/Spooldatei und arbeitet dann als lp.
install -m 700 -o root -g root "$source_dir/scripts/invoice-printer/kienzle-sumup-backend.py" /usr/lib/cups/backend/kienzle-sumup
install -d -m 755 /usr/share/ppd/kienzle-sumup
install -m 644 "$source_dir/scripts/invoice-printer/sumup-rechnung.ppd" /usr/share/ppd/kienzle-sumup/sumup-rechnung.ppd

echo 'Verbindung zum kienzle-sumup-Server prüfen …'
/usr/lib/cups/backend/kienzle-sumup --check

lpadmin -p "$queue" -E -v kienzle-sumup:/ -P /usr/share/ppd/kienzle-sumup/sumup-rechnung.ppd \
  -D "$queue" -L 'T2med-Rechnung an SumUp-Terminal' \
  -o printer-is-shared=true -o printer-error-policy=abort-job -o job-sheets-default=none,none
cupsenable "$queue"; cupsaccept "$queue"
if ! cupsctl | grep -q '^_share_printers=1'; then
  echo 'Hinweis: CUPS gibt Drucker noch nicht im Netz frei. Zum Freigeben: sudo cupsctl --share-printers'
fi
host=$(hostname)
echo "Fertig. Warteschlange $queue ist eingerichtet."
echo "Windows: IPP-Drucker http://$host:631/printers/$queue mit „Microsoft IPP Class Driver“ hinzufügen."
echo "Mac: „$queue @ $host“ hinzufügen, Treiber „Allgemeiner PDF-Drucker“ bzw. PDF-PPD wie beim Terminzettel."
echo 'In T2med die Rechnung auf diesen Drucker drucken und danach Kienzle-SumUp beim Patienten öffnen.'
