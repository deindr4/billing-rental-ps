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
TV_WS_HOST=billing.domainanda.com    # realtime TV lewat 443
TV_WS_PORT=443
TV_WS_SCHEME=https
```

`APP_KEY` **harus sama** dengan server lokal (kunci gateway terenkripsi dibaca kedua server).
Tanpa `TRUSTED_PROXIES` semua pengunjung terbaca ber-IP sama → batas percobaan (login, booking, bayar mandiri)
dipakai bersama semua orang, dan tautan bisa keluar `http://`. Jangan isi `*` bila server bisa diakses langsung.

Setelah mengubah `.env`: Admin → Pemeliharaan sistem → **Optimalkan**.

## Memasang server cloud (sekali)

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
