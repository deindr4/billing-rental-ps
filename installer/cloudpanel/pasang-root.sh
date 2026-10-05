#!/usr/bin/env bash
# Billing PS - langkah ROOT di server CloudPanel (sekali):
#   sudo bash pasang-root.sh
# 1. Setelan database untuk trigger sinkron (log_bin_trust_function_creators=1)
# 2. Supervisor: antrean (queue:work), realtime TV (Reverb 127.0.0.1:6001), jadwal (schedule:work - pengganti cron)
set -euo pipefail

DOMAIN="${DOMAIN:-cloudbill.deltagamesbali.id}"
# Folder site: /home/<folder>/htdocs/<domain>. Site user & grup = user PHP-FPM situs ini (yang menulis storage);
# nama folder home / user SSH bisa berbeda. Cadangan: pemilik folder site.
SITE="${SITE:-$(ls -d /home/*/htdocs/"$DOMAIN" 2>/dev/null | head -1)}"
POOL="$(grep -lE "$DOMAIN" /etc/php/*/fpm/pool.d/*.conf 2>/dev/null | head -1 || true)"
if [ -z "${SITE_USER:-}" ]; then
    if [ -n "$POOL" ]; then
        SITE_USER="$(sed -nE 's/^user *= *([^ ]+).*/\1/p' "$POOL" | head -1)"
        SITE_GROUP="${SITE_GROUP:-$(sed -nE 's/^group *= *([^ ]+).*/\1/p' "$POOL" | head -1)}"
    elif [ -n "$SITE" ]; then
        SITE_USER="$(stat -c %U "$SITE")"
    fi
fi
SITE_GROUP="${SITE_GROUP:-$(id -gn "${SITE_USER:-root}" 2>/dev/null || echo "")}"
PHP="${PHP:-/usr/bin/php8.4}"

merah() { printf '\033[31m%s\033[0m\n' "$*"; }
hijau() { printf '\033[32m%s\033[0m\n' "$*"; }
judul() { printf '\n\033[36m== %s ==\033[0m\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { merah "Jalankan sebagai root: sudo bash $0"; exit 1; }
[ -n "$SITE" ] && [ -d "$SITE" ] || { merah "Folder site /home/*/htdocs/$DOMAIN tidak ditemukan (atur DOMAIN / SITE)"; exit 1; }
[ "$SITE_USER" != root ] && id "$SITE_USER" >/dev/null 2>&1 || { merah "Site user tidak dikenali ($SITE_USER). Atur: SITE_USER=nama bash $0"; exit 1; }
hijau "Site: $SITE (user $SITE_USER, grup $SITE_GROUP)"

# Semua file site milik site user (file yang dibuat / diunggah sebagai root membuat site user tidak bisa menulis)
chown -R "$SITE_USER":"$SITE_GROUP" "$SITE"
[ -x "$PHP" ] || { merah "PHP tidak ada di $PHP (atur PHP=/usr/bin/php8.x)"; exit 1; }

judul "1. Database: log_bin_trust_function_creators"
# conf.d dibaca MySQL 8 maupun MariaDB versi paket Debian/Ubuntu
CNF=/etc/mysql/conf.d/99-billingps.cnf
if [ -f "$CNF" ] && grep -q log_bin_trust_function_creators "$CNF"; then
    hijau "Sudah diatur ($CNF)"
else
    printf '[mysqld]\nlog_bin_trust_function_creators=1\n' > "$CNF"
    if systemctl list-units --type=service --all | grep -q 'mariadb.service'; then LAYANAN=mariadb; else LAYANAN=mysql; fi
    echo "Memulai ulang $LAYANAN (situs lain di server ini terputus beberapa detik)..."
    systemctl restart "$LAYANAN"
    hijau "Diatur & $LAYANAN dimulai ulang"
fi

judul "2. Supervisor: antrean, realtime TV, jadwal"
# Port Reverb dari .env (pasang-site.sh: 6001; 8080 dipakai Nginx backend di template CloudPanel ber-Varnish)
PORT_WS="$(sed -nE 's/^REVERB_SERVER_PORT=([0-9]+).*/\1/p' "$SITE/.env" 2>/dev/null | head -1)"
PORT_WS="${PORT_WS:-6001}"
if ! command -v supervisorctl >/dev/null; then
    # apt bisa melaporkan error dari paket lain yang tertunda (mis. update CloudPanel setengah jalan)
    # walau supervisor sendiri terpasang: yang dicek hasil akhirnya
    apt-get update -qq || true
    apt-get install -y -qq supervisor || merah "apt melaporkan error (lihat di atas) - memeriksa supervisor..."
    command -v supervisorctl >/dev/null || { merah "Supervisor gagal dipasang."; exit 1; }
    hijau "Supervisor terpasang"
fi
systemctl enable --now supervisor >/dev/null 2>&1 || true
mkdir -p "$SITE/storage/logs" && chown "$SITE_USER":"$SITE_GROUP" "$SITE/storage/logs"

cat > /etc/supervisor/conf.d/billingps.conf <<EOF
[program:billingps-antrean]
command=$PHP $SITE/artisan queue:work --sleep=1 --tries=3 --max-time=3600
directory=$SITE
user=$SITE_USER
autostart=true
autorestart=true
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=$SITE/storage/logs/antrean.log
stdout_logfile_maxbytes=5MB

[program:billingps-realtime]
command=$PHP $SITE/artisan reverb:start --host=127.0.0.1 --port=$PORT_WS
directory=$SITE
user=$SITE_USER
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=$SITE/storage/logs/realtime.log
stdout_logfile_maxbytes=5MB

[program:billingps-jadwal]
command=$PHP $SITE/artisan schedule:work
directory=$SITE
user=$SITE_USER
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=$SITE/storage/logs/jadwal.log
stdout_logfile_maxbytes=5MB
EOF

supervisorctl reread
supervisorctl update
sleep 3
supervisorctl status | grep billingps || true

judul "3. Langkah berikut (aplikasi) - sebagai site user $SITE_USER"
echo "  su -s /bin/bash $SITE_USER -c \"SITE=$SITE bash $(cd "$(dirname "$0")" && pwd)/pasang-site.sh <file-paket.tar.gz>\""

hijau ""
hijau "Selesai. Jangan buka port $PORT_WS & 3306 di firewall (Reverb diakses lewat Nginx /app)."
echo "Tidak perlu Cron Job schedule:run di CloudPanel - jadwal sudah berjalan lewat supervisor (billingps-jadwal)."
