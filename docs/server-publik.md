# Server publik (cloud) & Cloudflare

Susunan yang dipakai: **server lokal** di rental (LAN, dipakai sehari-hari) + **server cloud** (VPS, alamat publik)
yang tersinkron. Sinkronisasi: `docs/sinkron.md`. Hak akses: `docs/hak-akses.md`.

## Fungsi server cloud

- Cadangan TV saat server lokal mati (failover otomatis).
- Halaman publik bisa dibuka dari internet: billboard, booking, turnamen, halaman bayar mandiri di HP (kuota sendiri).
- Callback payment gateway langsung masuk.
- Owner memantau dari luar rental.

## Port

**Cloudflare Tunnel (disarankan):** tidak ada port masuk yang dibuka. `cloudflared` di VPS membuka koneksi keluar.

**DNS Cloudflare biasa (awan oranye):**

| Port | Untuk |
|---|---|
| 443 | web, admin, API TV, sync, callback gateway |
| 80 | dialihkan ke 443 |
| 22 | SSH, batasi ke IP Anda |

Batasi 80/443 hanya untuk IP Cloudflare (cloudflare.com/ips).

**Jangan dibuka ke publik:** 3306 (MariaDB, sync lewat HTTPS + token) dan 8080 (Reverb — salurkan lewat 443).

**Server lokal:** tidak ada port yang dibuka ke internet. Firewall Windows hanya untuk LAN: 80 (web) & 8080 (realtime TV).

## `.env` server cloud

```
APP_ENV=production
APP_DEBUG=false
APP_MODE=cloud
APP_URL=https://billing.domainanda.com
LOG_LEVEL=warning
SESSION_SECURE_COOKIE=true
TRUSTED_PROXIES=127.0.0.1,::1        # Cloudflare Tunnel / reverse proxy di server yang sama
# TRUSTED_PROXIES=cloudflare          # DNS awan oranye tanpa tunnel (semua rentang IP Cloudflare)
TV_WS_HOST=billing.domainanda.com    # realtime TV lewat 443
TV_WS_PORT=443
TV_WS_SCHEME=https
```

`APP_KEY` **harus sama** dengan server lokal (kunci gateway terenkripsi dibaca kedua server).
Tanpa `TRUSTED_PROXIES` semua pengunjung terbaca ber-IP sama → batas percobaan (login, booking, bayar mandiri)
dipakai bersama semua orang, dan tautan bisa keluar `http://`. Jangan isi `*` bila server bisa diakses langsung.

Setelah mengubah `.env`: Admin → Pemeliharaan sistem → **Optimalkan**.

## HTTPS & `.htaccess`

`public/.htaccess` ikut aplikasi (otomatis), isinya bawaan Laravel: semua alamat ke `index.php` + meneruskan
header `Authorization` (token TV & sync). **Tidak ada** pengalihan http→https di situ — sengaja, karena file
yang sama dipakai server lokal (LAN, http).

Bila `APP_URL` diawali `https://`, aplikasi memaksa semua tautan, aset & redirect ke https
(mencegah *mixed content*, tombol Livewire/Filament mati, gambar tidak muncul).

| Masalah di cloud | Sebab | Perbaikan |
|---|---|---|
| Redirect berputar (*too many redirects*) | SSL Cloudflare **Flexible** + pengalihan https di server/`.htaccess` | SSL/TLS Cloudflare → **Full (strict)** dengan Origin Certificate; pengalihan cukup **Always Use HTTPS** di Cloudflare |
| Aset/tombol tidak jalan, konsol: *mixed content* | `APP_URL` masih `http://` atau cache config lama | `APP_URL=https://...` lalu **Optimalkan** |
| Login berulang / 419 Page Expired | Cookie aman tapi terbaca http, atau domain berganti | `SESSION_SECURE_COOKIE=true`, `TRUSTED_PROXIES` benar, buka lewat satu domain saja |
| Semua pengunjung satu IP, batas percobaan cepat habis | Proxy tidak dipercaya | `TRUSTED_PROXIES` (tunnel: `127.0.0.1,::1`; awan oranye: `cloudflare`) |
| `/admin` 404, hanya halaman depan yang jalan | Apache tanpa `mod_rewrite` / `AllowOverride None`, atau **Nginx** (tidak membaca `.htaccess`) | Apache: aktifkan `mod_rewrite`, `AllowOverride All` di folder `public`. Nginx: lihat di bawah |
| TV/APK & sync gagal, unggahan APK ditolak | Token terpotong / batas ukuran | Apache: `.htaccess` sudah meneruskan `Authorization`; Nginx: `client_max_body_size 200M` |

Cek: Admin → **Pemeliharaan sistem** → baris **HTTPS terbaca** harus *Ya* saat dibuka lewat https.

**Nginx** (bila VPS memakai Nginx, `.htaccess` diabaikan):

```nginx
server {
    listen 80;
    server_name billing.domainanda.com;
    root /var/www/billing-ps/public;
    index index.php;
    client_max_body_size 200M;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }
    # Realtime TV (Reverb) lewat 443
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 3600;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

**Apache** — realtime TV lewat 443 (aktifkan `mod_proxy`, `mod_proxy_wstunnel`), di dalam VirtualHost:

```apache
ProxyPass        /app ws://127.0.0.1:8080/app
ProxyPassReverse /app ws://127.0.0.1:8080/app
```

## Memasang server cloud (sekali)

Langkah lengkap untuk CloudPanel (paket aplikasi siap unggah, Nginx, cron, supervisor): [cloudpanel.md](cloudpanel.md).
Ringkasnya:

```bash
php artisan migrate --force
php artisan db:seed --class=HakAksesSeeder --force   # daftar izin, tanpa data demo
php artisan superadmin                                 # buat akun super admin
php artisan storage:link
```

Lalu login super admin → Pengaturan → Sinkronisasi → daftarkan token server lokal.
Scheduler wajib jalan (`php artisan schedule:run` tiap menit) + worker antrean + Reverb.

## Cloudflare

- Matikan **Bot Fight Mode**, atau buat aturan WAF yang melewatkan `/api/*` — APK TV & sync bukan browser,
  kalau ditantang captcha mereka gagal tanpa pesan jelas.
- Paket gratis membatasi unggahan **100 MB per file**: restore backup besar lebih aman di server lokal
  atau lewat terminal (`php artisan backup:pulihkan`).
- Websocket (Reverb) lewat Cloudflare bisa di 443; bila belum diatur, TV memakai polling (sedikit lebih lambat).

## Alamat server di TV

Admin server lokal → Pengaturan → Operasional → **Server lokal & cloud** → isi alamat lokal (`http://192.168.x.x`)
& cloud (`https://...`). TV menerima keduanya otomatis; tidak perlu diketik di TV.
Cek di TV: Home → OK → PIN → Pengaturan TV Agent → Info & diagnostik → Server.

## Checklist sebelum publik

1. Ganti password akun bawaan (`php artisan superadmin admin@billing.test`, Owner lewat Admin → Pengguna).
2. `.env` produksi seperti di atas, lalu **Optimalkan**.
3. Gateway sungguhan (bukan Simulasi), mode sandbox dulu.
4. Scheduler, antrean & Reverb berjalan.
5. Admin → **Pemeliharaan sistem**: tidak ada baris kuning.
