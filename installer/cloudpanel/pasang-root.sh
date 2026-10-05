#!/usr/bin/env bash
# Billing PS - langkah ROOT di server CloudPanel (sekali):
#   sudo bash pasang-root.sh
# 1. Setelan database untuk trigger sinkron (log_bin_trust_function_creators=1)
# 2. Supervisor: antrean (queue:work), realtime TV (Reverb :8080 lokal), jadwal (schedule:work - pengganti cron)
set -euo pipefail

DOMAIN="${DOMAIN:-cloudbill.deltagamesbali.id}"
SITE_USER="${SITE_USER:-indra}"
SITE="/home/$SITE_USER/htdocs/$DOMAIN"
PHP="${PHP:-/usr/bin/php8.4}"

merah() { printf '\033[31m%s\033[0m\n' "$*"; }
hijau() { printf '\033[32m%s\033[0m\n' "$*"; }
judul() { printf '\n\033[36m== %s ==\033[0m\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { merah "Jalankan sebagai root: sudo bash $0"; exit 1; }
[ -d "$SITE" ] || { merah "Folder site tidak ada: $SITE (atur DOMAIN / SITE_USER)"; exit 1; }
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
command -v supervisorctl >/dev/null || { apt-get update -qq && apt-get install -y -qq supervisor; }
mkdir -p "$SITE/storage/logs" && chown "$SITE_USER":"$SITE_USER" "$SITE/storage/logs"

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
command=$PHP $SITE/artisan reverb:start --host=127.0.0.1 --port=8080
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

hijau ""
hijau "Selesai. Jangan buka port 8080 & 3306 di firewall (Reverb diakses lewat Nginx /app)."
echo "Tidak perlu Cron Job schedule:run di CloudPanel - jadwal sudah berjalan lewat supervisor (billingps-jadwal)."
