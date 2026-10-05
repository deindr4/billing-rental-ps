#!/usr/bin/env bash
# Billing PS - pasang / update server cloud di CloudPanel. Jalankan sebagai SITE USER (bukan root):
#   bash pasang-site.sh [file-paket.tar.gz]
# Pertama kali: ekstrak paket, buat .env (isian database ditanya), migrasi, super admin, optimize.
# Sudah terpasang (.env ada): mode update (maintenance, backup, ekstrak, migrasi, optimize).
# Langkah root (database & supervisor): pasang-root.sh. Panduan: docs/cloudpanel.md
set -euo pipefail

DOMAIN="${DOMAIN:-cloudbill.deltagamesbali.id}"
SITE="${SITE:-$HOME/htdocs/$DOMAIN}"
PHP="${PHP:-php8.4}"
ZONA_BAWAAN="${ZONA:-Asia/Makassar}"

merah() { printf '\033[31m%s\033[0m\n' "$*"; }
hijau() { printf '\033[32m%s\033[0m\n' "$*"; }
judul() { printf '\n\033[36m== %s ==\033[0m\n' "$*"; }

[ "$(id -u)" -eq 0 ] && { merah "Jangan jalankan sebagai root. Masuk SSH sebagai site user (pemilik $SITE)."; exit 1; }
[ -d "$SITE" ] || { merah "Folder site tidak ada: $SITE (cek DOMAIN / buat site Laravel di CloudPanel dulu)"; exit 1; }
command -v "$PHP" >/dev/null || { merah "$PHP tidak ditemukan. Atur PHP=php8.x"; exit 1; }

# Paket salah tempat: aplikasi tidak boleh diekstrak ke dalam folder public (.env & kode bisa diunduh orang)
if [ -f "$SITE/public/artisan" ] || [ -f "$SITE/public/.env" ]; then
    merah "Aplikasi terekstrak di dalam folder public/ - BERBAHAYA (kode & .env bisa diunduh)."
    merah "Hapus isi $SITE/public lalu jalankan skrip ini lagi (paket diekstrak ke $SITE)."
    exit 1
fi

# Cari paket: argumen, lalu ~/ , folder site, folder public (bila terlanjur diunggah ke sana)
PAKET="${1:-}"
if [ -z "$PAKET" ]; then
    PAKET="$(ls -t "$HOME"/BillingPS-cloud-*.tar.gz "$SITE"/BillingPS-cloud-*.tar.gz "$SITE"/public/BillingPS-cloud-*.tar.gz 2>/dev/null | head -1 || true)"
fi
[ -n "$PAKET" ] && [ -f "$PAKET" ] || { merah "Paket BillingPS-cloud-*.tar.gz tidak ditemukan. Unggah ke $HOME atau sebut lokasinya."; exit 1; }
# Jangan tinggalkan paket di folder publik (bisa diunduh siapa saja)
if [[ "$PAKET" == "$SITE/public/"* ]]; then
    mv "$PAKET" "$HOME/" && PAKET="$HOME/$(basename "$PAKET")"
fi
hijau "Paket: $PAKET"

cd "$SITE"
art() { "$PHP" artisan "$@"; }

# Ubah / tambah KUNCI=nilai di .env
atur() {
    local kunci="$1" nilai="$2" aman
    aman="$(printf '%s' "$nilai" | sed -e 's/[\/&|]/\\&/g')"
    if grep -qE "^#? ?${kunci}=" .env; then
        sed -i -E "s|^#? ?${kunci}=.*|${kunci}=${aman}|" .env
    else
        printf '%s=%s\n' "$kunci" "$nilai" >> .env
    fi
}

if [ -f .env ]; then
    # ======================= UPDATE =======================
    judul "Update ke $(basename "$PAKET")"
    art down || true
    art backup:buat || merah "Backup gagal - lanjut? (Ctrl+C untuk batal)"; sleep 3
    tar -xzf "$PAKET" -C "$SITE"          # .env & isi storage tetap
    art migrate --force
    art db:seed --class=HakAksesSeeder --force
    art sync pasang-trigger
    art optimize
    art queue:restart
    art up
    hijau "Update selesai. Sebagai root: supervisorctl restart billingps-realtime billingps-jadwal"
    exit 0
fi

# ======================= PASANG PERTAMA =======================
judul "Ekstrak aplikasi ke $SITE"
rm -f "$SITE/index.php" "$SITE/index.html" "$SITE/public/index.html"
tar -xzf "$PAKET" -C "$SITE"
cp .env.example .env
chmod 600 .env

judul "Database (buat dulu di CloudPanel: Site > Databases > Add Database)"
read -rp "Nama database   : " DB_NAMA
read -rp "User database   : " DB_USER
read -rsp "Password DB     : " DB_PASS; echo
read -rp "Zona waktu [$ZONA_BAWAAN] (WIB Asia/Jakarta, WIT Asia/Jayapura): " ZONA; ZONA="${ZONA:-$ZONA_BAWAAN}"
read -rp "Domain lewat Cloudflare (awan oranye)? [Y/n]: " CF; CF="${CF:-Y}"
echo "APP_KEY dari PC rental (C:\\BillingPS\\app\\.env, baris APP_KEY=...) supaya kunci payment gateway terbaca"
read -rsp "  di kedua server. Kosongkan untuk membuat baru: " KUNCI; echo

atur APP_NAME '"Billing Rental PS"'
atur APP_ENV production
atur APP_DEBUG false
atur APP_URL "https://$DOMAIN"
atur APP_MODE cloud
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
atur SESSION_SECURE_COOKIE true
atur QUEUE_CONNECTION database
atur CACHE_STORE database
atur BROADCAST_CONNECTION reverb
atur REVERB_APP_ID "$(shuf -i 100000-999999 -n 1)"
atur REVERB_APP_KEY "$(openssl rand -hex 10)"
atur REVERB_APP_SECRET "$(openssl rand -hex 16)"
atur REVERB_HOST 127.0.0.1
atur REVERB_PORT 8080
atur REVERB_SCHEME http
atur REVERB_SERVER_HOST 127.0.0.1
atur REVERB_SERVER_PORT 8080
atur TV_WS_HOST "$DOMAIN"
atur TV_WS_PORT 443
atur TV_WS_SCHEME https
if [[ "$CF" =~ ^[Yy] ]]; then atur TRUSTED_PROXIES cloudflare; else atur TRUSTED_PROXIES ""; fi

if [ -n "$KUNCI" ]; then atur APP_KEY "$KUNCI"; else atur APP_KEY ""; art key:generate --force; fi

judul "Cek koneksi & setelan database"
TRUST="$("$PHP" -r '
    $e = []; foreach (file(".env") as $b) { if (preg_match("/^(DB_[A-Z]+)=(.*)$/", trim($b), $m)) { $e[$m[1]] = trim($m[2], "\""); } }
    try { $p = new PDO("mysql:host={$e["DB_HOST"]};port={$e["DB_PORT"]};dbname={$e["DB_DATABASE"]}", $e["DB_USERNAME"], $e["DB_PASSWORD"]);
          echo $p->query("SELECT @@log_bin_trust_function_creators")->fetchColumn(); }
    catch (Throwable $x) { echo "GAGAL: ".$x->getMessage(); }')"
case "$TRUST" in
    GAGAL*) merah "Tidak bisa masuk database: $TRUST"; merah "Perbaiki isian DB_* di $SITE/.env lalu jalankan: bash $0"; rm -f .env; exit 1 ;;
    0) merah "log_bin_trust_function_creators = 0 -> jalankan pasang-root.sh sebagai root dulu, lalu skrip ini lagi."; rm -f .env; exit 1 ;;
    *) hijau "Database OK" ;;
esac

judul "Tabel, hak akses & trigger sinkron"
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
chmod -R u+rwX storage bootstrap/cache
art migrate --force
art db:seed --class=HakAksesSeeder --force
art sync pasang-trigger
art storage:link || true

judul "Akun super admin (login panel cloud)"
art superadmin

art optimize
hijau ""
hijau "Selesai. Lanjutkan:"
echo "  1. Sebagai root: bash pasang-root.sh   (supervisor: antrean, realtime TV, jadwal)"
echo "  2. CloudPanel > Site > Vhost: tempel blok Nginx dari docs/cloudpanel.md langkah 5"
echo "  3. Buka https://$DOMAIN/admin > Pemeliharaan sistem (HTTPS terbaca = Ya)"
echo "  4. Sinkron: Pengaturan > Sinkronisasi > buat token > isi di PC rental (docs/sinkron.md)"
