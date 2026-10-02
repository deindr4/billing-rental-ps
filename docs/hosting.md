# Pasang server cloud di hosting (cPanel / shared hosting)

Alternatif murah dari VPS ([cloudpanel.md](cloudpanel.md)). Latar belakang: [server-publik.md](server-publik.md).

## Bisa & tidak bisa di hosting

| Fitur cloud | Hosting | Catatan |
|---|---|---|
| Admin, owner pantau dari luar | ✅ | |
| Billboard, booking, turnamen, bayar mandiri di HP | ✅ | |
| Callback payment gateway | ✅ | Langsung masuk |
| Sinkron lokal ↔ cloud | ✅* | *Butuh trigger database — **cek langkah 6**. Gagal = pilih VPS |
| Cadangan TV saat server lokal mati | ✅ | TV memakai **polling** (status diperbarui beberapa detik sekali) |
| Realtime TV (Reverb/websocket) | ❌ | Hosting tidak mengizinkan proses yang jalan terus |
| Antrean & jadwal | ✅ | Lewat Cron Jobs (minimal tiap menit; bila hosting membatasi 5 menit, cek bayar mandiri ikut 5 menit) |

Syarat hosting: **PHP 8.4** (minimal 8.3), MySQL 8 / MariaDB 10.6+, Cron Jobs, bisa mengatur *document root*
(sub)domain. Terminal/SSH sangat membantu (tidak wajib).

Contoh di bawah: user cPanel `rentalku`, subdomain `billing.domainanda.com`, folder aplikasi `/home/rentalku/billing`.

## 1. Siapkan paket (PC pengembang)

```powershell
powershell -ExecutionPolicy Bypass -File installer\build.ps1 -PaketCloud
```

Hasil: `installer\keluaran\BillingPS-cloud-<versi>.tar.gz` (aplikasi + `vendor` + aset; tanpa Node.js/composer di hosting).

## 2. PHP

cPanel → **Select PHP Version** (CloudLinux) / **MultiPHP Manager**:

1. Versi **8.4** untuk domain/subdomain billing.
2. Extensions aktif: `pdo_mysql`, `mysqlnd`, `mbstring`, `intl`, `gd`, `zip`, `fileinfo`, `exif`, `sodium`, `curl`, `openssl`.
3. Options / **MultiPHP INI Editor**: `memory_limit 512M`, `max_execution_time 300`, `max_input_time 300`,
   `post_max_size 256M`, `upload_max_filesize 256M` (bila hosting membatasi lebih kecil, unggah APK/backup di server lokal).

## 3. Database

cPanel → **MySQL Databases** (atau *Database Wizard*):
1. Buat database → mis. `rentalku_billing`.
2. Buat user → mis. `rentalku_billing`, password kuat (catat).
3. **Add User To Database** → centang **ALL PRIVILEGES** (termasuk TRIGGER).

## 4. Unggah aplikasi

1. cPanel → **File Manager** → folder home (`/home/rentalku`) → buat folder `billing`.
2. Masuk `billing` → **Upload** `BillingPS-cloud-<versi>.tar.gz` → klik kanan → **Extract** (ke `/home/rentalku/billing`).
3. Salin `.env.example` → `.env` (aktifkan *Show Hidden Files* di Settings File Manager), lalu **Edit**.

> Aplikasi **jangan** diekstrak ke dalam `public_html`: file `.env` & kode bisa diunduh orang.
> Yang boleh terbuka ke internet hanya folder `billing/public`.

## 5. Arahkan (sub)domain ke folder `public`

cPanel → **Domains** (atau *Subdomains*) → buat/ubah `billing.domainanda.com` →
**Document Root** = `billing/public`. Matikan *Share document root* bila ada.

SSL: cPanel → **SSL/TLS Status** → **Run AutoSSL** untuk subdomain itu (atau lewat Cloudflare, lihat bawah).

`.htaccess` di `billing/public` dipakai otomatis (Apache/LiteSpeed) — tidak perlu diubah.

Isi `.env`:

```
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:....                  # SAMA PERSIS dengan .env server lokal
APP_URL=https://billing.domainanda.com
APP_MODE=cloud
APP_TIMEZONE=Asia/Makassar
LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=rentalku_billing
DB_USERNAME=rentalku_billing
DB_PASSWORD=password-database

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
ANTREAN_LEWAT_CRON=true              # antrean dikerjakan cron (tidak ada queue:work di hosting)
BROADCAST_CONNECTION=null            # tanpa Reverb: TV otomatis polling

TRUSTED_PROXIES=                     # isi "cloudflare" bila domain lewat Cloudflare (awan oranye)
```

`APP_KEY` wajib sama dengan server lokal (kunci payment gateway terenkripsi dibaca kedua server).
File `.env` di PC rental hanya bisa dibuka Administrator (Notepad → *Run as administrator*).

## 6. Perintah pemasangan

Cari path PHP 8.4 di hosting Anda (biasanya salah satu):
`/opt/cpanel/ea-php84/root/usr/bin/php` · `/opt/alt/php84/usr/bin/php` · `/usr/local/bin/php`.
Di bawah ditulis `php` — ganti dengan path itu bila versi bawaan bukan 8.4 (`php -v` untuk cek).

**A. Ada Terminal** (cPanel → *Terminal*, atau SSH):

```bash
cd ~/billing
php artisan migrate --force
php artisan db:seed --class=HakAksesSeeder --force
php artisan sync pasang-trigger
php artisan superadmin admin@domainanda.com     # password ditanya
php artisan storage:link
php artisan optimize
```

**B. Tanpa Terminal** — pakai Cron Jobs **sekali jalan**:

1. cPanel → **Cron Jobs** → *Once Per Minute* → Command (satu baris; ganti path PHP, email & password sementara):

   ```
   cd /home/rentalku/billing && php artisan migrate --force && php artisan db:seed --class=HakAksesSeeder --force && php artisan sync pasang-trigger && php artisan superadmin admin@domainanda.com --password=SementaraSaja123 && php artisan storage:link && php artisan optimize > /home/rentalku/billing/storage/logs/pasang.txt 2>&1
   ```
2. Tunggu 1–2 menit, buka `billing/storage/logs/pasang.txt` di File Manager → pastikan tidak ada error.
3. **Hapus cron itu** (password ikut tertulis di sana), login, lalu ganti password super admin
   (jalankan lagi `superadmin` dengan password baru lewat cron sekali jalan, lalu hapus cron-nya).

**Kalau `sync pasang-trigger` gagal** dengan pesan *SUPER privilege … binary logging* / *TRIGGER command denied*:
hosting tidak mengizinkan trigger → perubahan yang terjadi di cloud (booking, pembayaran online) tidak terkirim
ke server lokal. Minta hosting mengaktifkan `log_bin_trust_function_creators` / hak TRIGGER, atau pakai VPS.

**Kalau `storage:link` gagal** (fungsi `symlink` dimatikan hosting): foto/logo yang diunggah di cloud tidak tampil.
Minta hosting mengaktifkan `symlink`, atau buat lewat Terminal: `ln -s ~/billing/storage/app/public ~/billing/public/storage`.

## 7. Cron jadwal (wajib, permanen)

cPanel → **Cron Jobs** → *Once Per Minute* (`* * * * *`):

```
cd /home/rentalku/billing && php artisan schedule:run >> /dev/null 2>&1
```

Ini menjalankan: antrean (`ANTREAN_LEWAT_CRON=true`), cek bayar mandiri, booking kedaluwarsa, backup harian,
pembersihan sinkron.

## 8. Cloudflare (opsional, disarankan)

- DNS A record subdomain → IP hosting, awan oranye; SSL/TLS **Full (strict)** (AutoSSL hosting tetap jalan) +
  **Always Use HTTPS**.
- `.env`: `TRUSTED_PROXIES=cloudflare`, lalu `php artisan optimize` (atau Admin → Pemeliharaan → Optimalkan).
- Bot Fight Mode **Off** / WAF *Skip* untuk `/api/` (APK TV & sync).

Tanpa Cloudflare: biarkan `TRUSTED_PROXIES` kosong.

## 9. Cek & sambungkan

1. `https://billing.domainanda.com/admin` → login super admin.
2. Admin → **Pemeliharaan sistem**: tidak ada baris kuning; **HTTPS terbaca = Ya**; **Kolom waktu otomatis = aman**.
3. Sinkron: Pengaturan → **Sinkronisasi** → token ([sinkron.md](sinkron.md)), isi di server lokal.
4. Server lokal → Pengaturan → Operasional → **Server lokal & cloud** → isi `https://billing.domainanda.com`.

## Update versi baru

1. Admin cloud → Pemeliharaan → **Backup**.
2. Unggah paket baru ke `billing` → **Extract** (timpa; `.env` & isi `storage` tetap).
3. Terminal / cron sekali jalan:
   ```
   cd /home/rentalku/billing && php artisan migrate --force && php artisan db:seed --class=HakAksesSeeder --force && php artisan sync pasang-trigger && php artisan optimize
   ```
   (atau Admin → Pemeliharaan: **Update database**, **Pasang ulang trigger**, **Optimalkan**).

## Bila bermasalah

| Gejala | Periksa |
|---|---|
| 500 Server Error | `billing/storage/logs/laravel-*.log`; versi PHP subdomain 8.4; extension lengkap |
| Halaman kosong / daftar file | Document root belum `billing/public` |
| 404 di `/admin`, halaman depan jalan | `.htaccess` di `billing/public` hilang (aktifkan *Show Hidden Files* saat extract) |
| 419 / login berulang | `APP_URL` https + `SESSION_SECURE_COOKIE=true`; di balik Cloudflare isi `TRUSTED_PROXIES=cloudflare` |
| APK TV / sync ditolak (403/406) | ModSecurity / Imunify360 hosting memblokir `/api/*` → minta whitelist ke hosting; Bot Fight Mode Cloudflare |
| Bayar mandiri lambat terdeteksi | Cron tidak tiap menit; callback gateway tetap langsung |
| 413 / unggahan gagal | Batas unggah hosting; unggah APK & backup besar di server lokal |
| Akun hosting "Resource limit reached" | Batas CPU/proses hosting — kurangi TV yang diarahkan ke cloud atau pakai VPS |
