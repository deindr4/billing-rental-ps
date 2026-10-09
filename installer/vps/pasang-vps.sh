#!/usr/bin/env bash
# =====================================================================================================
# Delta Billing HuB (dulu Billing Rental PS) - pasang di VPS polos (Ubuntu 22.04/24.04, Debian 12) dalam satu perintah.
#
#   curl -fsSL https://raw.githubusercontent.com/deindr4/billing-rental-ps/master/installer/vps/pasang-vps.sh -o pasang-vps.sh
#   sudo bash pasang-vps.sh                      # tanya isian (domain, mode, nama rental, ...)
#   sudo bash pasang-vps.sh BillingPS-cloud-2026.10.09.tar.gz   # pakai paket lokal (tanpa unduh)
#
# Memasang & menyetel otomatis sesuai RAM: Nginx (gzip, cache statis, HTTP/2, websocket TV), PHP 8.4-FPM (+OPcache),
# MariaDB (buffer pool, trigger sinkron), Supervisor (antrean, realtime TV, jadwal), swap, SSL Let's Encrypt (domain).
# Selesai: menampilkan alamat akses, username & password (juga disimpan di /root/billingps-login.txt).
# Dijalankan lagi saat sudah terpasang = UPDATE ke versi terbaru (data & .env tetap).
#
# Isian bisa lewat variabel (tanpa ditanya): DOMAIN EMAIL MODE(utama|cloud) RENTAL OWNER_EMAIL ZONA VERSI APP_KEY_LOKAL
# Panduan: docs/vps.md
# =====================================================================================================
set -euo pipefail

REPO="deindr4/billing-rental-ps"
PHPV="8.4"
APP_USER="billingps"
APP_DIR="/home/$APP_USER/app"
DB_NAMA="billingps"
DB_USER="billingps"
PORT_WS=6001
CATAT=/root/billingps-login.txt
PAKET_LOKAL="${1:-}"

merah() { printf '\033[31m%s\033[0m\n' "$*"; }
hijau() { printf '\033[32m%s\033[0m\n' "$*"; }
kuning() { printf '\033[33m%s\033[0m\n' "$*"; }
judul() { printf '\n\033[36m== %s ==\033[0m\n' "$*"; }
acak() { tr -dc 'A-Za-z0-9' </dev/urandom | head -c "${1:-16}" || true; }
tanya() { # tanya VAR "Pertanyaan" "bawaan" — dilewati bila VAR sudah diisi lewat lingkungan
    local var="$1" teks="$2" bawaan="${3:-}" isi
    if [ -n "${!var:-}" ]; then return; fi
    if [ -t 0 ]; then read -rp "$teks${bawaan:+ [$bawaan]}: " isi || true; else isi=""; fi
    printf -v "$var" '%s' "${isi:-$bawaan}"
}

[ "$(id -u)" -eq 0 ] || { merah "Jalankan sebagai root: sudo bash $0"; exit 1; }
[ -f /etc/os-release ] || { merah "Sistem operasi tidak dikenali."; exit 1; }
. /etc/os-release
case "${ID:-}" in
    ubuntu|debian) ;;
    *) merah "Hanya untuk Ubuntu 22.04/24.04 atau Debian 12 (terdeteksi: ${PRETTY_NAME:-?})."; exit 1 ;;
esac
export DEBIAN_FRONTEND=noninteractive

RAM_MB=$(( $(awk '/MemTotal/ {print $2}' /proc/meminfo) / 1024 ))
CPU=$(nproc)
IP_PUBLIK="$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')"
art() { sudo -u "$APP_USER" php "$APP_DIR/artisan" "$@"; }

# ====================================================================================================
#  MODE UPDATE: sudah terpasang
# ====================================================================================================
if [ -f "$APP_DIR/.env" ]; then
    judul "Delta Billing HuB sudah terpasang di $APP_DIR → UPDATE"
    if [ -z "$PAKET_LOKAL" ]; then
        VERSI="${VERSI:-$(curl -fsS "https://api.github.com/repos/$REPO/releases/latest" | grep -o '"tag_name": *"[^"]*"' | cut -d'"' -f4 | sed 's/^v//')}"
        PAKET_LOKAL="/root/BillingPS-cloud-$VERSI.tar.gz"
        curl -fL --progress-bar -o "$PAKET_LOKAL" "https://github.com/$REPO/releases/download/v$VERSI/BillingPS-cloud-$VERSI.tar.gz"
    fi
    art down || true
    art backup:buat || kuning "Backup gagal — lanjut dalam 5 detik (Ctrl+C untuk batal)"; sleep 5
    tar -xzf "$PAKET_LOKAL" -C "$APP_DIR"
    chown -R "$APP_USER":"$APP_USER" "$APP_DIR"
    art vendor:publish --tag=livewire:assets --force >/dev/null
    art migrate --force
    art db:seed --class=HakAksesSeeder --force
    art sync pasang-trigger
    # Nama aplikasi baru: ganti hanya bila masih nama bawaan lama
    sed -i -E 's/^APP_NAME="?(Billing Rental PS|Billing PS)"?$/APP_NAME="Delta Billing HuB"/' "$APP_DIR/.env"
    art optimize
    art queue:restart
    art up
    systemctl reload "php$PHPV-fpm"
    supervisorctl restart billingps-realtime billingps-jadwal billingps-antrean >/dev/null || true
    hijau "Update selesai: versi $(cat "$APP_DIR/VERSION" 2>/dev/null). Login & password tidak berubah (lihat $CATAT)."
    exit 0
fi

# ====================================================================================================
#  ISIAN
# ====================================================================================================
judul "Delta Billing HuB — pasang baru (RAM ${RAM_MB} MB, ${CPU} CPU, IP $IP_PUBLIK)"
echo "Domain / subdomain yang sudah diarahkan (A record) ke IP $IP_PUBLIK, mis. billing.rentalku.com."
echo "Kosongkan untuk akses lewat IP saja (http, tanpa SSL)."
tanya DOMAIN "Domain"
DOMAIN="$(printf '%s' "$DOMAIN" | tr 'A-Z' 'a-z' | sed -E 's#^https?://##; s#/.*$##')"
if [ -n "$DOMAIN" ]; then
    tanya EMAIL "Email untuk sertifikat SSL (Let's Encrypt)" "admin@${DOMAIN#*.}"
fi
echo
echo "Mode: 1 = server utama (VPS dipakai langsung, tanpa PC rental)"
echo "      2 = cloud pendamping PC rental (data datang lewat sinkron dari PC rental)"
tanya MODE "Pilih mode 1/2" "1"
case "$MODE" in 2|cloud) MODE=cloud ;; *) MODE=utama ;; esac
if [ "$MODE" = utama ]; then
    tanya RENTAL "Nama rental" "Rental PS"
    tanya OWNER_EMAIL "Email login owner" "owner@${DOMAIN:-billing.lokal}"
else
    echo "APP_KEY dari PC rental (C:\\BillingPS\\app\\.env baris APP_KEY=base64:...) supaya kunci payment gateway"
    echo "terbaca di kedua server. Kosongkan untuk membuat baru."
    tanya APP_KEY_LOKAL "APP_KEY PC rental"
fi
tanya ZONA "Zona waktu (Asia/Jakarta WIB, Asia/Makassar WITA, Asia/Jayapura WIT)" "Asia/Makassar"

# ====================================================================================================
#  1. PAKET SISTEM
# ====================================================================================================
judul "1. Memasang paket sistem (Nginx, PHP $PHPV, MariaDB, Supervisor)"
apt-get update -qq
apt-get install -y -qq ca-certificates curl gnupg lsb-release unzip software-properties-common apt-transport-https sudo >/dev/null
if ! apt-cache policy "php$PHPV-fpm" 2>/dev/null | grep -q 'Candidate: [0-9]'; then
    if [ "$ID" = ubuntu ]; then
        add-apt-repository -y ppa:ondrej/php >/dev/null
    else
        curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg
        echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/sury-php.list
    fi
    apt-get update -qq
fi
apt-get install -y -qq nginx mariadb-server supervisor certbot python3-certbot-nginx \
    "php$PHPV-fpm" "php$PHPV-cli" "php$PHPV-mysql" "php$PHPV-mbstring" "php$PHPV-xml" "php$PHPV-curl" "php$PHPV-zip" \
    "php$PHPV-gd" "php$PHPV-intl" "php$PHPV-bcmath" "php$PHPV-opcache" "php$PHPV-readline" >/dev/null
update-alternatives --set php "/usr/bin/php$PHPV" >/dev/null 2>&1 || true
timedatectl set-timezone "$ZONA" 2>/dev/null || true
hijau "Paket terpasang: $(php -r 'echo PHP_VERSION;'), $(mariadb --version | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1) MariaDB, $(nginx -v 2>&1 | cut -d/ -f2) Nginx"

# Swap untuk VPS kecil (mencegah MariaDB / PHP dimatikan saat memori penuh)
if [ "$RAM_MB" -lt 3000 ] && [ "$(swapon --show | wc -l)" -eq 0 ]; then
    fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    sysctl -q vm.swappiness=10 && echo 'vm.swappiness=10' > /etc/sysctl.d/99-billingps.conf
    hijau "Swap 2 GB dibuat (RAM ${RAM_MB} MB)"
fi

# ====================================================================================================
#  2. TUNING MARIADB (sesuai RAM)
# ====================================================================================================
judul "2. Tuning MariaDB"
BUFFER=$(( RAM_MB * 30 / 100 )); [ "$BUFFER" -lt 128 ] && BUFFER=128
LOGFILE=$(( BUFFER / 4 )); [ "$LOGFILE" -lt 48 ] && LOGFILE=48; [ "$LOGFILE" -gt 512 ] && LOGFILE=512
KONEKSI=$(( RAM_MB >= 4000 ? 200 : 100 ))
PERF=$([ "$RAM_MB" -ge 4000 ] && echo ON || echo OFF)
cat > /etc/mysql/mariadb.conf.d/99-billingps.cnf <<EOF
# Billing PS - disetel otomatis untuk RAM ${RAM_MB} MB oleh pasang-vps.sh
[mysqld]
bind-address                   = 127.0.0.1
skip-name-resolve
character-set-server           = utf8mb4
collation-server               = utf8mb4_unicode_ci
# Wajib: trigger sinkron lokal<->cloud & kolom waktu tidak ter-reset otomatis
log_bin_trust_function_creators = 1
explicit_defaults_for_timestamp = ON
innodb_buffer_pool_size        = ${BUFFER}M
innodb_log_file_size           = ${LOGFILE}M
innodb_flush_method            = O_DIRECT
innodb_file_per_table          = 1
max_connections                = ${KONEKSI}
table_open_cache               = 2000
tmp_table_size                 = 32M
max_heap_table_size            = 32M
performance_schema             = ${PERF}
slow_query_log                 = 1
long_query_time                = 2
EOF
systemctl restart mariadb
hijau "MariaDB: buffer pool ${BUFFER} MB, log ${LOGFILE} MB, ${KONEKSI} koneksi"

DB_PASS="$(acak 24)"
mariadb <<EOF
CREATE DATABASE IF NOT EXISTS \`$DB_NAMA\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAMA\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF
hijau "Database $DB_NAMA & user $DB_USER dibuat"

# ====================================================================================================
#  3. TUNING PHP-FPM (pool khusus aplikasi)
# ====================================================================================================
judul "3. Tuning PHP $PHPV-FPM & OPcache"
id "$APP_USER" >/dev/null 2>&1 || useradd -m -s /bin/bash "$APP_USER"
usermod -aG "$APP_USER" www-data
chmod 750 "/home/$APP_USER"

ANAK=$(( RAM_MB / 2 / 64 )); [ "$ANAK" -lt 4 ] && ANAK=4; [ "$ANAK" -gt 50 ] && ANAK=50
MULAI=$(( ANAK / 4 )); [ "$MULAI" -lt 2 ] && MULAI=2
OPC=$([ "$RAM_MB" -ge 2000 ] && echo 192 || echo 128)
cat > "/etc/php/$PHPV/fpm/pool.d/billingps.conf" <<EOF
; Billing PS - disetel otomatis untuk RAM ${RAM_MB} MB
[billingps]
user = $APP_USER
group = $APP_USER
listen = /run/php/billingps.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = $ANAK
pm.start_servers = $MULAI
pm.min_spare_servers = $MULAI
pm.max_spare_servers = $(( ANAK / 2 > MULAI ? ANAK / 2 : MULAI + 1 ))
pm.max_requests = 500
request_terminate_timeout = 120
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 64M
php_admin_value[post_max_size] = 64M
php_admin_value[max_execution_time] = 120
EOF
# Pool bawaan "www" tidak dipakai: dimatikan supaya tidak memakan RAM
[ -f "/etc/php/$PHPV/fpm/pool.d/www.conf" ] && mv "/etc/php/$PHPV/fpm/pool.d/www.conf" "/etc/php/$PHPV/fpm/pool.d/www.conf.nonaktif"
cat > "/etc/php/$PHPV/mods-available/zz-billingps.ini" <<EOF
; Billing PS - OPcache & cache path (berlaku FPM & CLI)
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=$OPC
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=60
realpath_cache_size=4096K
realpath_cache_ttl=600
expose_php=Off
EOF
phpenmod -v "$PHPV" zz-billingps
systemctl restart "php$PHPV-fpm"
hijau "PHP-FPM: maks $ANAK proses (mulai $MULAI), OPcache ${OPC} MB"

# ====================================================================================================
#  4. APLIKASI
# ====================================================================================================
judul "4. Aplikasi"
if [ -z "$PAKET_LOKAL" ]; then
    VERSI="${VERSI:-$(curl -fsS "https://api.github.com/repos/$REPO/releases/latest" | grep -o '"tag_name": *"[^"]*"' | cut -d'"' -f4 | sed 's/^v//')}"
    [ -n "$VERSI" ] || { merah "Tidak bisa membaca versi terbaru dari GitHub. Unduh paket manual lalu: sudo bash $0 <paket.tar.gz>"; exit 1; }
    PAKET_LOKAL="/root/BillingPS-cloud-$VERSI.tar.gz"
    echo "Mengunduh versi $VERSI..."
    curl -fL --progress-bar -o "$PAKET_LOKAL" "https://github.com/$REPO/releases/download/v$VERSI/BillingPS-cloud-$VERSI.tar.gz"
fi
[ -f "$PAKET_LOKAL" ] || { merah "Paket tidak ditemukan: $PAKET_LOKAL"; exit 1; }
mkdir -p "$APP_DIR"
tar -xzf "$PAKET_LOKAL" -C "$APP_DIR"
cd "$APP_DIR"
cp .env.example .env

atur() { # KUNCI nilai di .env
    local kunci="$1" nilai="$2" aman
    aman="$(printf '%s' "$nilai" | sed -e 's/[\/&|]/\\&/g')"
    if grep -qE "^#? ?${kunci}=" .env; then sed -i -E "s|^#? ?${kunci}=.*|${kunci}=${aman}|" .env; else printf '%s=%s\n' "$kunci" "$nilai" >> .env; fi
}

# Alamat & HTTPS ditentukan setelah SSL; sementara http
HOST="${DOMAIN:-$IP_PUBLIK}"
atur APP_NAME '"Delta Billing HuB"'
atur APP_ENV production
atur APP_DEBUG false
atur APP_URL "http://$HOST"
atur APP_MODE "$([ "$MODE" = cloud ] && echo cloud || echo local)"
atur APP_TIMEZONE "$ZONA"
atur LOG_CHANNEL daily
atur LOG_LEVEL warning
atur DB_CONNECTION mysql
atur DB_HOST 127.0.0.1
atur DB_PORT 3306
atur DB_DATABASE "$DB_NAMA"
atur DB_USERNAME "$DB_USER"
atur DB_PASSWORD "\"$DB_PASS\""
atur SESSION_DRIVER database
atur QUEUE_CONNECTION database
atur CACHE_STORE database
atur BROADCAST_CONNECTION reverb
atur REVERB_APP_ID "$(shuf -i 100000-999999 -n 1)"
atur REVERB_APP_KEY "$(openssl rand -hex 10)"
atur REVERB_APP_SECRET "$(openssl rand -hex 16)"
atur REVERB_HOST 127.0.0.1
atur REVERB_PORT "$PORT_WS"
atur REVERB_SCHEME http
atur REVERB_SERVER_HOST 127.0.0.1
atur REVERB_SERVER_PORT "$PORT_WS"
atur TRUSTED_PROXIES "127.0.0.1,::1,cloudflare"
if [ -n "${APP_KEY_LOKAL:-}" ]; then atur APP_KEY "$APP_KEY_LOKAL"; else atur APP_KEY ""; fi

chown -R "$APP_USER":"$APP_USER" "$APP_DIR"
chmod 600 .env
[ -n "${APP_KEY_LOKAL:-}" ] || art key:generate --force
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
chown -R "$APP_USER":"$APP_USER" storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

art vendor:publish --tag=livewire:assets --force >/dev/null
art migrate --force
art db:seed --class=HakAksesSeeder --force
art sync pasang-trigger
art storage:link >/dev/null 2>&1 || true

SU_EMAIL="superadmin@${DOMAIN:-billing.lokal}"
SU_PASS="$(acak 14)"
if [ "$MODE" = utama ]; then
    OWNER_PASS="$(acak 12)"
    PIN="$(shuf -i 100000-999999 -n 1)"
    art pasang:awal --rental="$RENTAL" --zona="$ZONA" --owner-email="$OWNER_EMAIL" --owner-password="$OWNER_PASS" \
        --pin="$PIN" --superadmin-email="$SU_EMAIL"
fi
# Super admin dengan password sendiri (mode utama: menimpa password bawaan pasang:awal yang sama dengan owner)
art superadmin "$SU_EMAIL" --password="$SU_PASS" >/dev/null
hijau "Aplikasi terpasang: versi $(cat VERSION 2>/dev/null)"

# ====================================================================================================
#  5. SUPERVISOR (antrean, realtime TV, jadwal)
# ====================================================================================================
judul "5. Layanan latar (Supervisor)"
cat > /etc/supervisor/conf.d/billingps.conf <<EOF
[program:billingps-antrean]
command=/usr/bin/php$PHPV $APP_DIR/artisan queue:work --sleep=1 --tries=3 --max-time=3600
directory=$APP_DIR
user=$APP_USER
autostart=true
autorestart=true
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=$APP_DIR/storage/logs/antrean.log
stdout_logfile_maxbytes=5MB

[program:billingps-realtime]
command=/usr/bin/php$PHPV $APP_DIR/artisan reverb:start --host=127.0.0.1 --port=$PORT_WS
directory=$APP_DIR
user=$APP_USER
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=$APP_DIR/storage/logs/realtime.log
stdout_logfile_maxbytes=5MB

[program:billingps-jadwal]
command=/usr/bin/php$PHPV $APP_DIR/artisan schedule:work
directory=$APP_DIR
user=$APP_USER
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=$APP_DIR/storage/logs/jadwal.log
stdout_logfile_maxbytes=5MB
EOF
systemctl enable --now supervisor >/dev/null 2>&1 || true
sleep 2
supervisorctl reread >/dev/null && supervisorctl update >/dev/null
hijau "Antrean, realtime TV (Reverb 127.0.0.1:$PORT_WS) & jadwal berjalan"

# ====================================================================================================
#  6. TUNING NGINX + SITUS
# ====================================================================================================
judul "6. Tuning Nginx"
[ -f /etc/nginx/nginx.conf.asli ] || cp /etc/nginx/nginx.conf /etc/nginx/nginx.conf.asli
cat > /etc/nginx/nginx.conf <<EOF
# Billing PS - disetel otomatis (${CPU} CPU) oleh pasang-vps.sh; aslinya: nginx.conf.asli
user www-data;
worker_processes auto;
worker_rlimit_nofile 65535;
pid /run/nginx.pid;
include /etc/nginx/modules-enabled/*.conf;

events {
    worker_connections 4096;
    multi_accept on;
}

http {
    sendfile on;
    tcp_nopush on;
    tcp_nodelay on;
    keepalive_timeout 30;
    keepalive_requests 1000;
    types_hash_max_size 2048;
    server_tokens off;
    client_max_body_size 64m;
    client_body_buffer_size 128k;

    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;

    access_log off;
    error_log /var/log/nginx/error.log warn;

    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 5;
    gzip_min_length 512;
    gzip_types text/plain text/css text/xml application/json application/javascript application/xml image/svg+xml font/woff2;

    open_file_cache max=5000 inactive=30s;
    open_file_cache_valid 60s;
    open_file_cache_min_uses 2;

    fastcgi_buffers 32 32k;
    fastcgi_buffer_size 64k;

    include /etc/nginx/conf.d/*.conf;
    include /etc/nginx/sites-enabled/*;
}
EOF

NAMA_SERVER="${DOMAIN:-_}"
cat > /etc/nginx/sites-available/billingps <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name $NAMA_SERVER;
    root $APP_DIR/public;
    index index.php;
    charset utf-8;

    # Aset build Vite (nama berisi hash) & Livewire: simpan lama di browser
    location /build/ { expires 1y; add_header Cache-Control "public, immutable"; try_files \$uri =404; }
    location /vendor/ { expires 30d; add_header Cache-Control "public"; try_files \$uri =404; }
    location ~* \.(?:ico|png|jpe?g|webp|gif|svg|woff2?|mp4)$ { expires 7d; add_header Cache-Control "public"; try_files \$uri /index.php?\$query_string; }

    # Folder unggahan hanya berisi gambar: jangan pernah menjalankan skrip di sana
    location ~* ^/storage/.*\.(php\d?|phtml|phar|pht)$ { deny all; }
    location ~ /\.(?!well-known) { deny all; }

    # Realtime TV (Reverb websocket)
    location /app {
        proxy_pass http://127.0.0.1:$PORT_WS;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host \$host;
        proxy_read_timeout 3600;
    }

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/billingps.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }
}
EOF
rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/billingps /etc/nginx/sites-enabled/billingps
nginx -t
systemctl reload nginx
hijau "Nginx: gzip, cache aset statis, websocket /app, worker otomatis (${CPU} CPU)"

# Firewall: hanya ditambah aturannya bila ufw sudah aktif (tidak mengaktifkan ufw — mencegah SSH terkunci)
if command -v ufw >/dev/null && ufw status | grep -q 'Status: active'; then
    ufw allow 'Nginx Full' >/dev/null && hijau "ufw: port 80 & 443 dibuka"
fi

# ====================================================================================================
#  7. SSL (domain)
# ====================================================================================================
SKEMA=http
if [ -n "$DOMAIN" ]; then
    judul "7. SSL Let's Encrypt untuk $DOMAIN"
    IP_DOMAIN="$(getent ahostsv4 "$DOMAIN" | awk 'NR==1{print $1}')"
    [ "$IP_DOMAIN" = "$IP_PUBLIK" ] || kuning "DNS $DOMAIN → ${IP_DOMAIN:-belum ada} (server ini $IP_PUBLIK). Bila lewat Cloudflare (awan oranye) tetap dicoba."
    if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --redirect -m "${EMAIL:-admin@$DOMAIN}" >/tmp/certbot.log 2>&1; then
        SKEMA=https
        # HTTP/2 di blok 443 yang dibuat certbot
        sed -i -E 's/listen (\[::\]:)?443 ssl;/listen \1443 ssl http2;/' /etc/nginx/sites-available/billingps
        nginx -t >/dev/null 2>&1 && systemctl reload nginx
        hijau "SSL aktif, HTTP dialihkan ke HTTPS (perpanjang otomatis)"
    else
        merah "SSL gagal (lihat /tmp/certbot.log). Penyebab umum: DNS belum mengarah ke $IP_PUBLIK, atau port 80 diblok."
        kuning "Lewat Cloudflare: set SSL/TLS = Flexible, atau ulangi nanti: certbot --nginx -d $DOMAIN --redirect"
        if [ "$IP_DOMAIN" != "$IP_PUBLIK" ] && [ -n "$IP_DOMAIN" ]; then SKEMA=https; fi # di belakang Cloudflare: pengunjung tetap https
    fi
fi

cd "$APP_DIR"
atur APP_URL "$SKEMA://$HOST"
atur SESSION_SECURE_COOKIE "$([ "$SKEMA" = https ] && echo true || echo false)"
atur TV_WS_HOST "$HOST"
atur TV_WS_PORT "$([ "$SKEMA" = https ] && echo 443 || echo 80)"
atur TV_WS_SCHEME "$SKEMA"
art optimize >/dev/null
supervisorctl restart billingps-realtime billingps-antrean billingps-jadwal >/dev/null || true

# ====================================================================================================
#  SELESAI: login & alamat akses
# ====================================================================================================
URL="$SKEMA://$HOST"
{
    echo "Delta Billing HuB — dipasang $(date '+%d/%m/%Y %H:%M') di $(hostname) (IP $IP_PUBLIK)"
    echo "Alamat aplikasi : $URL"
    echo "Panel admin     : $URL/admin"
    if [ "$MODE" = utama ]; then
        echo "Login OWNER     : $OWNER_EMAIL"
        echo "Password owner  : $OWNER_PASS"
        echo "PIN owner       : $PIN   (persetujuan di kasir & TV)"
    fi
    echo "SUPER ADMIN     : $SU_EMAIL"
    echo "Password SA     : $SU_PASS"
    echo "Database        : $DB_NAMA / user $DB_USER / password $DB_PASS"
    echo "Folder aplikasi : $APP_DIR (user $APP_USER)"
} > "$CATAT"
chmod 600 "$CATAT"

printf '\n\033[42;30m%s\033[0m\n' "  BILLING RENTAL PS SIAP DIPAKAI  "
echo
printf '  %-17s \033[1m%s\033[0m\n' "Alamat aplikasi" "$URL"
printf '  %-17s %s\n' "Panel admin" "$URL/admin"
printf '  %-17s %s\n' "IP server" "$IP_PUBLIK"
echo
if [ "$MODE" = utama ]; then
    printf '  %-17s \033[1m%s\033[0m\n' "Login owner" "$OWNER_EMAIL"
    printf '  %-17s \033[1m%s\033[0m\n' "Password owner" "$OWNER_PASS"
    printf '  %-17s \033[1m%s\033[0m\n' "PIN owner" "$PIN"
    echo
fi
printf '  %-17s %s\n' "Super admin" "$SU_EMAIL"
printf '  %-17s %s\n' "Password SA" "$SU_PASS"
echo
kuning "  Catat & simpan di tempat aman. Salinan: $CATAT (hanya root). Ganti password setelah login pertama."
if [ "$MODE" = cloud ]; then
    echo
    echo "  Berikutnya: login super admin → Pengaturan → Sinkronisasi → buat token → isi di PC rental (docs/sinkron.md)."
fi
echo "  Update nanti: jalankan lagi  sudo bash $0"
