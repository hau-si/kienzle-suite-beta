#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
source_dir=$PWD
if [ "$EUID" -ne 0 ]; then echo 'Bitte mit sudo ausführen: sudo bash scripts/install-server.sh'; exit 1; fi
if [ ! -f /etc/debian_version ]; then echo 'Dieser Installer unterstützt Debian, Ubuntu und Raspberry Pi OS.'; exit 1; fi
if ! command -v systemctl >/dev/null; then echo 'systemd wird benötigt.'; exit 1; fi
umask 077
# Optionen: --fragen (Einstellungen wie früher im Terminal abfragen), --admin-reset (neuer Einrichtungslink).
ask_flag=""; admin_reset=""
for arg in "$@"; do
  case "$arg" in
    --fragen) ask_flag="--fragen" ;;
    --admin-reset) admin_reset="--reset" ;;
    *) echo "Unbekannte Option: $arg (erlaubt: --fragen, --admin-reset)"; exit 2 ;;
  esac
done
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y apache2 php-cli php-fpm php-curl php-sqlite3 php-mbstring openssl ca-certificates python3 curl librsvg2-bin fonts-dejavu-core libphp-phpmailer poppler-utils php-gd smbclient cups-client cups
php -r 'exit(PHP_VERSION_ID >= 80100 && extension_loaded("sodium") && extension_loaded("pdo_sqlite") && extension_loaded("curl") ? 0 : 1);' || { echo 'PHP >= 8.1 mit sodium, sqlite und curl benötigt.'; exit 1; }
if ! id kienzle-sumup >/dev/null 2>&1; then useradd --system --home-dir /var/lib/kienzle-sumup --shell /usr/sbin/nologin kienzle-sumup; fi
install -d -m 750 -o root -g kienzle-sumup /etc/kienzle-sumup
install -d -m 700 -o kienzle-sumup -g kienzle-sumup /var/lib/kienzle-sumup
# Drucker im Browser einrichten: CUPS-Verwaltung über die Gruppe lpadmin (nur lokaler Druckdienst).
usermod -aG lpadmin kienzle-sumup
systemctl enable --now cups.service >/dev/null 2>&1 || true
install -d -m 750 -o kienzle-sumup -g kienzle-sumup /var/log/kienzle-sumup
python3 scripts/configure.py $ask_flag
chown root:kienzle-sumup /etc/kienzle-sumup/kienzle-sumup.toml
chmod 640 /etc/kienzle-sumup/kienzle-sumup.toml
if [ -f /etc/kienzle-sumup/t2med-ca.pem ]; then chown root:kienzle-sumup /etc/kienzle-sumup/t2med-ca.pem; fi
chmod 600 /etc/kienzle-sumup/server.key
chmod 644 /etc/kienzle-sumup/server.crt
# Ab hier nur die eigenen Dienste stoppen; vorhandene Apache-Konfigurationen nicht ändern.
systemctl stop kienzle-sumup-apache.service kienzle-sumup-php.service 2>/dev/null || true
touch /var/log/kienzle-sumup/php.log
chown kienzle-sumup:kienzle-sumup /var/log/kienzle-sumup/php.log
chmod 640 /var/log/kienzle-sumup/php.log

install -d -m 755 /opt/kienzle-sumup
for item in src public scripts config client VERSION README.md; do
  if [ -e "$source_dir/$item" ] && [ "$source_dir" != /opt/kienzle-sumup ]; then cp -R "$source_dir/$item" /opt/kienzle-sumup/; fi
done
chown -R root:root /opt/kienzle-sumup
find /opt/kienzle-sumup -type d -exec chmod 755 {} +
find /opt/kienzle-sumup -type f -exec chmod 644 {} +
port=$(php -r 'require $argv[1]; echo KienzleSumup\Config::load("/etc/kienzle-sumup/kienzle-sumup.toml")->get("app","port");' "$source_dir/src/bootstrap.php")
php_version=$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')
fpm="/usr/sbin/php-fpm$php_version"
test -x "$fpm" || { echo "PHP-FPM $php_version fehlt."; exit 1; }
cat > /etc/kienzle-sumup/php-fpm.conf <<EOF
[global]
pid = /run/kienzle-sumup-php/php.pid
error_log = /var/log/kienzle-sumup/fpm.log
daemonize = no
[kienzle-sumup]
user = kienzle-sumup
group = kienzle-sumup
listen = /run/kienzle-sumup-php/php.sock
listen.owner = kienzle-sumup
listen.group = kienzle-sumup
listen.mode = 0600
pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 10s
pm.max_requests = 500
request_terminate_timeout = 90s
clear_env = yes
security.limit_extensions = .php
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_flag[expose_php] = off
php_admin_value[memory_limit] = 64M
php_admin_value[post_max_size] = 8M
php_admin_value[error_log] = /var/log/kienzle-sumup/php.log
EOF
cat > /etc/kienzle-sumup/apache.conf <<EOF
ServerRoot /etc/kienzle-sumup
ServerName localhost
Listen $port https
PidFile /run/kienzle-sumup-apache/apache.pid
DefaultRuntimeDir /run/kienzle-sumup-apache
LoadModule mpm_event_module /usr/lib/apache2/modules/mod_mpm_event.so
LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so
LoadModule authz_host_module /usr/lib/apache2/modules/mod_authz_host.so
LoadModule dir_module /usr/lib/apache2/modules/mod_dir.so
LoadModule mime_module /usr/lib/apache2/modules/mod_mime.so
LoadModule ssl_module /usr/lib/apache2/modules/mod_ssl.so
LoadModule socache_shmcb_module /usr/lib/apache2/modules/mod_socache_shmcb.so
LoadModule proxy_module /usr/lib/apache2/modules/mod_proxy.so
LoadModule proxy_fcgi_module /usr/lib/apache2/modules/mod_proxy_fcgi.so
LoadModule setenvif_module /usr/lib/apache2/modules/mod_setenvif.so
LoadModule headers_module /usr/lib/apache2/modules/mod_headers.so
LoadModule reqtimeout_module /usr/lib/apache2/modules/mod_reqtimeout.so
User kienzle-sumup
Group kienzle-sumup
ServerTokens Prod
ServerSignature Off
TraceEnable Off
Timeout 90
ProxyTimeout 80
KeepAlive On
MaxKeepAliveRequests 50
KeepAliveTimeout 3
StartServers 1
ServerLimit 2
ThreadsPerChild 16
MaxRequestWorkers 32
TypesConfig /etc/mime.types
ErrorLog /var/log/kienzle-sumup/apache-error.log
LogLevel warn
# Kein AccessLog: Starttickets, Patienten-/Vorgangskennungen werden nicht protokolliert.
SSLSessionCache shmcb:/run/kienzle-sumup-apache/sslcache(512000)
<Directory />
    AllowOverride None
    Require all denied
</Directory>
<VirtualHost *:$port>
    SSLEngine on
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLCertificateFile /etc/kienzle-sumup/server.crt
    SSLCertificateKeyFile /etc/kienzle-sumup/server.key
    DocumentRoot /opt/kienzle-sumup/public
    DirectoryIndex index.php
    LimitRequestBody 32768
    Header always set X-Content-Type-Options nosniff
    Header always set Referrer-Policy no-referrer
    <Directory /opt/kienzle-sumup/public>
        Options -Indexes -ExecCGI -Includes
        AllowOverride None
        Require all granted
        <FilesMatch "\\.php$">
            SetHandler "proxy:unix:/run/kienzle-sumup-php/php.sock|fcgi://localhost"
        </FilesMatch>
    </Directory>
    # Rechnungseingang vom Druckserver: PDF bis 5 MB, sonst gilt das kleine Limit oben.
    <Location "/invoice-inbox.php">
        LimitRequestBody 5300000
    </Location>
    SetEnvIfNoCase Authorization "^(.*)$" HTTP_AUTHORIZATION=\$1
</VirtualHost>
EOF
cat > /etc/systemd/system/kienzle-sumup-php.service <<EOF
[Unit]
Description=Kienzle-SumUp PHP-FPM
After=network.target
[Service]
Type=simple
ExecStart=$fpm --nodaemonize --fpm-config /etc/kienzle-sumup/php-fpm.conf
ExecReload=/bin/kill -USR2 \$MAINPID
RuntimeDirectory=kienzle-sumup-php
RuntimeDirectoryMode=0755
UMask=0077
Restart=on-failure
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
ReadWritePaths=/var/lib/kienzle-sumup /var/log/kienzle-sumup /run/kienzle-sumup-php
[Install]
WantedBy=multi-user.target
EOF
cat > /etc/systemd/system/kienzle-sumup-apache.service <<'EOF'
[Unit]
Description=Kienzle-SumUp Apache HTTPS
After=network.target kienzle-sumup-php.service
Requires=kienzle-sumup-php.service
[Service]
Type=simple
ExecStart=/usr/sbin/apache2 -f /etc/kienzle-sumup/apache.conf -DFOREGROUND
ExecReload=/bin/kill -USR1 $MAINPID
RuntimeDirectory=kienzle-sumup-apache
RuntimeDirectoryMode=0755
UMask=0077
Restart=on-failure
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
ReadWritePaths=/var/log/kienzle-sumup /run/kienzle-sumup-apache
[Install]
WantedBy=multi-user.target
EOF
chmod 644 /etc/systemd/system/kienzle-sumup-{apache,php}.service
chmod 640 /etc/kienzle-sumup/{apache.conf,php-fpm.conf}
runuser -u kienzle-sumup -- php /opt/kienzle-sumup/scripts/init-db.php
install -d -m 755 /run/kienzle-sumup-apache /run/kienzle-sumup-php
/usr/sbin/apache2 -f /etc/kienzle-sumup/apache.conf -t
"$fpm" --fpm-config /etc/kienzle-sumup/php-fpm.conf -t
for file in install-linux.sh install-macos.command install-windows.ps1 install-windows.cmd; do
  install -m 700 "client/$file" "/root/kienzle-sumup-clients/$file"
done
if [ -d dist ]; then cp dist/kienzle-sumup-* dist/SHA256SUMS /root/kienzle-sumup-clients/; fi
# Starterprogramme für die Download-Pakete der Einstellungsseite (öffentliche Release-Dateien,
# per SHA256SUMS geprüft). Ohne Internet laden die Arbeitsplätze sie bei der Einrichtung selbst.
starter_version=v1.4.1
install -d -m 755 /opt/kienzle-sumup/client-dist
if [ -d dist ] && [ -f dist/SHA256SUMS ]; then
  cp dist/kienzle-sumup-* dist/SHA256SUMS /opt/kienzle-sumup/client-dist/
elif ! (cd /opt/kienzle-sumup/client-dist && test -f SHA256SUMS && sha256sum --quiet --ignore-missing -c SHA256SUMS >/dev/null 2>&1 && grep -q "$starter_version" .version 2>/dev/null); then
  starter_tmp=$(mktemp -d)
  if curl -fsSL --proto '=https' --tlsv1.2 --max-time 60 "https://github.com/thomaskien/t2med-sumup/releases/download/$starter_version/SHA256SUMS" -o "$starter_tmp/SHA256SUMS"; then
    for asset in kienzle-sumup-windows-amd64.exe kienzle-sumup-windows-arm64.exe kienzle-sumup-darwin-amd64 kienzle-sumup-darwin-arm64 kienzle-sumup-linux-amd64 kienzle-sumup-linux-arm64 kienzle-sumup-linux-arm; do
      curl -fsSL --proto '=https' --tlsv1.2 --max-time 120 "https://github.com/thomaskien/t2med-sumup/releases/download/$starter_version/$asset" -o "$starter_tmp/$asset" || rm -f "$starter_tmp/$asset"
    done
    if (cd "$starter_tmp" && sha256sum --quiet --ignore-missing -c SHA256SUMS); then
      cp "$starter_tmp"/* /opt/kienzle-sumup/client-dist/ && echo "$starter_version" > /opt/kienzle-sumup/client-dist/.version
    else
      echo 'Warnung: Prüfsumme der Starterprogramme stimmt nicht; Pakete ohne Starterprogramme.'
    fi
  else
    echo 'Hinweis: Starterprogramme nicht geladen (kein Internet?). Die Arbeitsplätze laden sie bei der Einrichtung selbst.'
  fi
  rm -rf "$starter_tmp"
fi
chmod 644 /opt/kienzle-sumup/client-dist/* 2>/dev/null || true
# Stündliche Nachholung der Rechnungsablage (ohne aktivierte Ablage ohne Wirkung).
if true; then
  cat > /etc/systemd/system/kienzle-sumup-export.service <<'UNIT'
[Unit]
Description=Kienzle-SumUp Rechnungsablage nachholen
After=network-online.target
[Service]
Type=oneshot
User=kienzle-sumup
Group=kienzle-sumup
ExecStart=/usr/bin/php /opt/kienzle-sumup/scripts/export-invoices.php --quiet
UMask=0077
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
ReadWritePaths=/var/lib/kienzle-sumup /var/log/kienzle-sumup
UNIT
  cat > /etc/systemd/system/kienzle-sumup-export.timer <<'UNIT'
[Unit]
Description=Kienzle-SumUp Rechnungsablage stündlich nachholen
[Timer]
OnBootSec=10min
OnUnitActiveSec=1h
RandomizedDelaySec=5min
[Install]
WantedBy=timers.target
UNIT
  chmod 644 /etc/systemd/system/kienzle-sumup-export.service /etc/systemd/system/kienzle-sumup-export.timer
  export_timer=1
fi
systemctl daemon-reload
systemctl enable --now kienzle-sumup-php.service kienzle-sumup-apache.service
if [ "$export_timer" = 1 ]; then systemctl enable --now kienzle-sumup-export.timer; fi
echo "Bereit auf HTTPS-Port $port. Client-Einrichtung: /root/kienzle-sumup-clients"
echo 'Diesen Ordner geschützt auf die Arbeitsplätze übertragen; er enthält den Starter-Schlüssel.'
echo 'Die Firewall bei Bedarf ausschließlich für das Praxisnetz freigeben.'
echo
# Einstellungen im Browser: beim ersten Mal (oder nach --admin-reset) mit einmaligem Einrichtungslink.
setup_url=$(runuser -u kienzle-sumup -- php /opt/kienzle-sumup/scripts/admin-setup.php $admin_reset)
if [ -n "$setup_url" ]; then
  echo 'Ersteinrichtung im Browser (Link einmalig gültig, nicht weitergeben):'
  echo "  $setup_url"
  echo 'Dort Admin-Passwort festlegen und SumUp, T2med, Drucker usw. eintragen.'
else
  echo "Einstellungen: $(runuser -u kienzle-sumup -- php -r 'require "/opt/kienzle-sumup/src/bootstrap.php"; echo rtrim(ks_base_config()->get("app","base_url"),"/");')/settings.php"
  echo 'Admin-Passwort vergessen? sudo bash scripts/install-server.sh --admin-reset'
fi
