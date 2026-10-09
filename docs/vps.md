# Pasang di VPS polos (satu perintah)

Untuk VPS **baru / kosong** Ubuntu 22.04 / 24.04 atau Debian 12 (tanpa CloudPanel). Bila sudah memakai CloudPanel,
pakai [cloudpanel.md](cloudpanel.md).

## Sebelum mulai

1. Domain atau subdomain (mis. `billing.rentalku.com`) → **A record** ke IP VPS. Lewat Cloudflare boleh
   (awan oranye), tapi Let's Encrypt paling mudah bila sementara **awan abu-abu** (DNS only).
2. Login SSH sebagai root (atau user ber-sudo). Port 80 & 443 terbuka di firewall penyedia VPS.
3. Minimal 1 vCPU · 1 GB RAM (disarankan 2 GB). Lihat [README → Kebutuhan sistem](../README.md#kebutuhan-sistem).

## Pasang

```bash
curl -fsSL https://raw.githubusercontent.com/deindr4/billing-rental-ps/master/installer/vps/pasang-vps.sh -o pasang-vps.sh
sudo bash pasang-vps.sh
```

Isian yang ditanya:

| Isian | Keterangan |
|---|---|
| Domain | `billing.rentalku.com` — kosongkan untuk akses lewat IP (http, tanpa SSL) |
| Email SSL | untuk Let's Encrypt (pemberitahuan kedaluwarsa) |
| Mode | **1 = server utama** (VPS dipakai langsung, tanpa PC rental: rental, owner & PIN dibuat otomatis) · **2 = cloud pendamping PC rental** (data datang lewat sinkron; hanya super admin) |
| Nama rental, email owner | mode 1 |
| APP_KEY PC rental | mode 2, opsional (supaya kunci payment gateway terbaca di kedua server) |
| Zona waktu | WIB `Asia/Jakarta`, WITA `Asia/Makassar`, WIT `Asia/Jayapura` |

Tanpa ditanya (otomasi): `DOMAIN=billing.rentalku.com MODE=utama RENTAL="Delta Games" OWNER_EMAIL=owner@rentalku.com ZONA=Asia/Makassar sudo -E bash pasang-vps.sh`.
Paket lokal (tanpa unduh dari GitHub): `sudo bash pasang-vps.sh BillingPS-cloud-<versi>.tar.gz`.

## Yang dipasang & disetel otomatis

| Bagian | Isi |
|---|---|
| Paket | Nginx, PHP 8.4-FPM (PPA ondrej / sury), MariaDB, Supervisor, Certbot |
| MariaDB | buffer pool ±30% RAM, log InnoDB, koneksi 100/200, `log_bin_trust_function_creators=1` (trigger sinkron), `explicit_defaults_for_timestamp=ON`, hanya `127.0.0.1`, slow query log. File: `/etc/mysql/mariadb.conf.d/99-billingps.cnf` |
| PHP-FPM | pool khusus `billingps` (user sendiri), jumlah proses sesuai RAM, OPcache 128–192 MB, realpath cache; pool bawaan `www` dimatikan |
| Nginx | worker otomatis sesuai CPU, gzip, keepalive, cache browser aset (`/build` 1 tahun, Livewire 30 hari, gambar 7 hari), websocket `/app` → Reverb, blokir skrip di `/storage`, HTTP/2 + SSL. Asli: `/etc/nginx/nginx.conf.asli` |
| Swap | 2 GB bila RAM < 3 GB dan belum ada swap |
| Layanan | Supervisor: `billingps-antrean`, `billingps-realtime` (Reverb 127.0.0.1:6001), `billingps-jadwal` |
| SSL | Let's Encrypt + alih HTTP→HTTPS + perpanjang otomatis (bila domain sudah mengarah ke VPS) |
| Firewall | bila `ufw` aktif, port 80/443 dibuka (ufw tidak diaktifkan otomatis supaya SSH tidak terkunci) |

## Selesai

Layar akhir menampilkan **alamat aplikasi, panel admin, IP server, login & password owner + PIN (mode 1), super admin**.
Salinannya di `/root/billingps-login.txt` (hanya root), termasuk password database. Ganti password setelah login pertama.

Mode 2: login super admin → *Pengaturan → Sinkronisasi* → buat token → isi di PC rental ([sinkron.md](sinkron.md)).

## Update

Jalankan lagi skrip yang sama: `sudo bash pasang-vps.sh` — terdeteksi sudah terpasang → backup database, unduh rilis
terbaru, migrasi, optimasi, restart layanan. Login & data tidak berubah. Versi tertentu: `VERSI=2026.10.09 sudo -E bash pasang-vps.sh`.

## Masalah umum

| Gejala | Cek |
|---|---|
| SSL gagal | DNS belum mengarah ke IP VPS / port 80 diblok / Cloudflare awan oranye → ulangi `certbot --nginx -d domain --redirect`, atau Cloudflare SSL = Flexible |
| 502 Bad Gateway | `systemctl status php8.4-fpm`, log `/var/log/nginx/error.log` |
| TV tidak realtime | `supervisorctl status`, `TV_WS_*` di `/home/billingps/app/.env` |
| Lupa password owner | `cd /home/billingps/app && sudo -u billingps php artisan pasang:awal --daftar-owner` lalu reset dari panel super admin |
