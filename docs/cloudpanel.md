# Pasang server cloud di CloudPanel

Server cloud = cadangan TV, halaman publik (billboard, booking, turnamen, bayar mandiri), callback gateway.
Latar belakang & port: [server-publik.md](server-publik.md). Sinkron: [sinkron.md](sinkron.md).

Contoh di bawah: domain `billing.domainanda.com`, site user `billing`, PHP 8.4. Ganti sesuai milik Anda.

## 0. Siapkan paket aplikasi (PC pengembang)

```powershell
powershell -ExecutionPolicy Bypass -File installer\build.ps1 -PaketCloud
```

Hasil: `installer\keluaran\BillingPS-cloud-<versi>.tar.gz` — sudah berisi `vendor` & aset (`public/build`),
jadi di VPS **tidak perlu** Node.js maupun `composer install`.

## 1. Cloudflare (DNS)

1. DNS → A record `billing` → IP VPS, **awan oranye** (proxied).
2. SSL/TLS → mode **Full (strict)**. Jangan *Flexible* (penyebab redirect berputar).
3. SSL/TLS → Origin Server → **Create Certificate** (15 tahun) → simpan *certificate* & *private key*.
4. SSL/TLS → Edge Certificates → **Always Use HTTPS** = On.
5. Security → **Bot Fight Mode** = Off (atau aturan WAF *Skip* untuk path `/api/`) — APK TV & sync bukan browser.

## 2. Buat site di CloudPanel

1. **Add Site → Create a PHP Site** → Application: **Laravel** → domain `billing.domainanda.com`,
   PHP **8.4**, site user `billing` (catat password-nya).
   Template Laravel otomatis mengarahkan root ke folder `public`.
2. Site → **SSL/TLS → Actions → Import Certificate** → tempel Origin Certificate & private key dari langkah 1.3.
3. Site → **Settings → PHP Settings**:

   | Isian | Nilai |
   |---|---|
   | memory_limit | 512M |
   | max_execution_time / max_input_time | 300 |
   | post_max_size / upload_max_filesize | 256M |

   *Additional configuration*: `date.timezone=Asia/Makassar` (WIB: `Asia/Jakarta`, WIT: `Asia/Jayapura`).

## 3. Database

1. Site → **Databases → Add Database**: nama `billing_ps`, user `billing_ps`, password kuat (catat).
2. Trigger sinkron butuh satu setelan server. SSH sebagai **root**:

   ```bash
   clpctl db:show:master-credentials          # user & password root database
   mysql -uroot -p -e "SELECT @@version, @@explicit_defaults_for_timestamp, @@log_bin_trust_function_creators;"
   ```

   - `explicit_defaults_for_timestamp` harus **1** (MySQL 8 & MariaDB ≥ 10.10 bawaannya 1).
   - `log_bin_trust_function_creators` harus **1**, kalau 0:
     - MySQL 8: `mysql -uroot -p -e "SET PERSIST log_bin_trust_function_creators=1;"`
     - MariaDB: tambahkan `log_bin_trust_function_creators=1` di bagian `[mysqld]` file konfigurasi
       (`/etc/mysql/mariadb.conf.d/50-server.cnf`), lalu `systemctl restart mariadb`.

   Tanpa ini `migrate` / `sync pasang-trigger` gagal dengan pesan *SUPER privilege … binary logging*.

## 4. Unggah aplikasi

Unggah `BillingPS-cloud-<versi>.tar.gz` ke `/home/billing/` (CloudPanel **File Manager** atau SFTP dengan site user),
lalu SSH sebagai **site user** `billing`:

```bash
cd ~/htdocs/billing.domainanda.com
rm -f index.php index.html                   # file contoh bawaan site (bila ada)
tar -xzf ~/BillingPS-cloud-*.tar.gz
cp .env.example .env
nano .env
```

Isi `.env` (selain yang tidak disebut, biarkan bawaan):

```
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:....                  # SAMA PERSIS dengan .env server lokal (C:\BillingPS\app\.env)
APP_URL=https://billing.domainanda.com
APP_MODE=cloud
APP_TIMEZONE=Asia/Makassar
LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=billing_ps
DB_USERNAME=billing_ps
DB_PASSWORD=password-database-langkah-3

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=123456                 # angka acak
REVERB_APP_KEY=isi-acak-20-huruf
REVERB_APP_SECRET=isi-acak-32-huruf
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080

TV_WS_HOST=billing.domainanda.com    # TV terhubung realtime lewat 443
TV_WS_PORT=443
TV_WS_SCHEME=https

TRUSTED_PROXIES=cloudflare
```

`APP_KEY` wajib sama dengan server lokal: kunci payment gateway terenkripsi dibaca kedua server.
(File `.env` di PC rental hanya bisa dibuka Administrator — Notepad → *Run as administrator*.)

Lalu, masih sebagai site user:

```bash
php8.4 artisan migrate --force
php8.4 artisan db:seed --class=HakAksesSeeder --force
php8.4 artisan sync pasang-trigger
php8.4 artisan superadmin                    # buat akun super admin (email & password ditanya)
php8.4 artisan storage:link
php8.4 artisan optimize
```

## 5. Nginx: realtime TV & batas unggahan

Site → **Vhost** → di dalam blok `server { ... }` (sebelum `location ~ \.php$`), tambahkan:

```nginx
client_max_body_size 256M;

location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600;
}
```

Simpan (CloudPanel memuat ulang Nginx otomatis). `.htaccess` tidak dipakai Nginx — template Laravel CloudPanel sudah
mengatur pengalihan ke `index.php`.

## 6. Jadwal, antrean & realtime

**Jadwal** — Site → **Cron Jobs → Add Cron Job**: setiap menit (`* * * * *`), perintah:

```
/usr/bin/php8.4 /home/billing/htdocs/billing.domainanda.com/artisan schedule:run >> /dev/null 2>&1
```

**Antrean & Reverb** — SSH sebagai **root**:

```bash
apt install -y supervisor
nano /etc/supervisor/conf.d/billingps.conf
```

```ini
[program:billingps-antrean]
command=/usr/bin/php8.4 /home/billing/htdocs/billing.domainanda.com/artisan queue:work --sleep=1 --tries=3 --max-time=3600
user=billing
autostart=true
autorestart=true
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=/home/billing/htdocs/billing.domainanda.com/storage/logs/antrean.log

[program:billingps-realtime]
command=/usr/bin/php8.4 /home/billing/htdocs/billing.domainanda.com/artisan reverb:start --host=127.0.0.1 --port=8080
user=billing
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/home/billing/htdocs/billing.domainanda.com/storage/logs/realtime.log
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl status
```

## 7. Firewall CloudPanel

Admin Area → **Security → Firewall**:
- 80 & 443: biarkan (lebih ketat: hanya IP Cloudflare, cloudflare.com/ips).
- 22 (SSH) & 8443 (panel CloudPanel): batasi ke IP Anda.
- **Jangan** buka 8080 (Reverb) & 3306 (database).

## 8. Cek & sambungkan

1. Buka `https://billing.domainanda.com/admin` → login super admin.
2. Admin → **Pemeliharaan sistem**: tidak ada baris kuning, **HTTPS terbaca = Ya**.
3. Sinkron: Pengaturan → **Sinkronisasi** → buat token (tombol acak) → isi token & alamat cloud di server lokal
   ([sinkron.md](sinkron.md)).
4. Server lokal → Pengaturan → Operasional → **Server lokal & cloud** → isi `https://billing.domainanda.com`
   supaya TV bisa pindah ke cloud saat server lokal mati.

## Update versi baru

Bangun paket baru (`build.ps1 -PaketCloud`), unggah, lalu sebagai site user:

```bash
cd ~/htdocs/billing.domainanda.com
php8.4 artisan down
php8.4 artisan backup:buat
tar -xzf ~/BillingPS-cloud-<versi-baru>.tar.gz      # .env & isi storage tetap
php8.4 artisan migrate --force
php8.4 artisan db:seed --class=HakAksesSeeder --force
php8.4 artisan sync pasang-trigger
php8.4 artisan optimize
php8.4 artisan queue:restart
php8.4 artisan up
```

Lalu sebagai root: `supervisorctl restart billingps-realtime`.

## Bila bermasalah

| Gejala | Periksa |
|---|---|
| 500 Server Error | `storage/logs/laravel-*.log`; izin folder: `storage` & `bootstrap/cache` milik site user |
| Redirect berputar | Cloudflare SSL masih *Flexible* → **Full (strict)** |
| Tombol/aset mati, 419 | `APP_URL` https, `TRUSTED_PROXIES=cloudflare`, lalu `php8.4 artisan optimize` |
| 413 saat unggah APK/backup | `client_max_body_size` di Vhost + PHP Settings (langkah 2.3 & 5) |
| TV tidak realtime (polling) | `supervisorctl status`, blok `location /app` di Vhost, `TV_WS_*` di `.env` |
| `migrate` gagal *SUPER privilege* | langkah 3.2 `log_bin_trust_function_creators` |
| APK TV / sync gagal tanpa pesan | Cloudflare Bot Fight Mode / WAF menantang `/api/*` |

Detail HTTPS umum: [server-publik.md](server-publik.md) bagian *HTTPS & .htaccess*.
