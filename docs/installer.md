# Installer Windows Delta Billing HuB (tahap 14)

Nama aplikasi **Delta Billing HuB** (sejak 2026.10.09.3; dulu Billing Rental PS). Nama internal tetap: folder
`C:\BillingPS`, layanan `BillingPS-*`, file `BillingPS-Setup/cloud/Monitor`, database `billing_ps` — update versi lama mulus.
Saat update, `APP_NAME` di `.env` diganti otomatis hanya bila masih nama bawaan lama; pintasan nama lama dihapus.

Panduan bergambar untuk pemilik rental (pasang sampai transaksi pertama): `public/tutorial/windows.html` / `.pdf`,
dibuka di aplikasi lewat `http://IP-PC/tutorial-windows`. Dokumen ini catatan teknis installernya.

Satu file `BillingPS-Setup-<versi>.exe` untuk PC rental yang masih bersih (Windows 10/11 64-bit).
Isi: PHP 8.4, Apache 2.4.68, MariaDB 10.11 LTS, Visual C++ runtime 14.50+, Node.js (WhatsApp), cloudflared,
aplikasi siap pakai, APK TV.

## Memasang di PC rental

1. Klik dua kali `BillingPS-Setup-….exe` (minta izin Administrator).
2. Wizard:
   - **Folder** — bawaan `C:\BillingPS`.
   - **Jenis pemasangan** — **Pasang baru** (data kosong) atau **Pulihkan dari file backup** (pindah PC / pasang ulang).
   - Pulihkan: **File backup** (`backup-….zip` dari Admin → Platform → Backup → Unduh, atau
     `data\storage\app\private\backup` di PC lama). Halaman data rental & akun owner dilewati (diambil dari backup).
   - Pasang baru: **Data rental** (nama rental & cabang) dan **Akun owner** (nama, email, password min. 8, PIN 4–6 angka).
   - **Zona waktu** — WIB / WITA / WIT.
   - **Port web** — biarkan 80 kecuali dipakai program lain.
3. Tunggu "Menyiapkan database, aplikasi & layanan" (beberapa menit).
4. **Data login** tampil di kotak pesan (harus ditutup dulu) lalu lagi di halaman Selesai: email, username, password,
   PIN, super admin & alamat aplikasi (mis. `http://192.168.1.10`). Password **tidak** disimpan ke file mana pun.
   Centang "Buka Delta Billing HuB sekarang" untuk langsung membuka aplikasi.
5. Ikon **Delta Billing HuB** di Desktop membuka aplikasi di PC itu.

Pulihkan dari backup — urutan otomatis: buat tabel → pulihkan isi backup (data & logo) → lengkapi tabel/kolom versi baru
→ hak akses & trigger sync → alamat server lokal TV diganti ke IP PC ini (bila satu cabang). Yang perlu diisi ulang
karena kunci enkripsi PC baru berbeda: API key payment gateway, token Telegram, scan QR WhatsApp. TV: bila IP PC berubah,
ganti alamat server di TV (menu staf); kode darurat TV muncul lagi setelah TV dipasangkan ulang.

Akun yang dibuat:

| Akun | Login | Password |
|---|---|---|
| Owner | email di wizard | password di wizard |
| Super admin | `superadmin@billing.lokal` | sama dengan owner — ganti: `php artisan superadmin superadmin@billing.lokal` |

Yang dipasang otomatis:

- **Layanan Windows** (menyala sendiri saat PC dinyalakan): `BillingPS-Database`, `BillingPS-Web`,
  `BillingPS-Realtime` (TV), `BillingPS-Antrean`, `BillingPS-Jadwal` (cek pembayaran QRIS, sync, backup harian),
  `BillingPS-WhatsApp` (laporan & notifikasi WA; Node.js ikut dibundel — **tanpa npm install**).
- **WhatsApp**: port (3001, atau 3002/3003/3011 bila terpakai) & token acak dibuat otomatis, ditulis ke `.env`
  (`WA_SERVICE_URL`, `WA_SERVICE_TOKEN`). Hanya `127.0.0.1` (tidak dibuka di firewall). Tinggal login:
  Admin → Pengaturan → **Notifikasi & Laporan** → scan QR dari HP WhatsApp. Sesi login di `data\whatsapp-sesi` (aman saat update).
- **Firewall**: port web & realtime TV (8080) dibuka **hanya untuk jaringan lokal**.
- Database dengan kata sandi acak (tersimpan di `C:\BillingPS\kelola\konfigurasi.json`, hanya Administrator).
- APK TV terbaru terdaftar sebagai rilis → di TV buka `http://IP-PC/apk`.

## Akses dari internet (Cloudflare Tunnel)

`cloudflared.exe` & layanan `BillingPS-Tunnel` ikut terpasang tapi **mati** sampai diaktifkan — tidak perlu
mengunduh / menjalankan cloudflared sendiri di Windows. Owner: Admin → Pengaturan → **Cloudflare Tunnel**:

1. one.dash.cloudflare.com → Networks → Tunnels → **Create a tunnel** → Cloudflared → beri nama.
2. Pilih Windows, salin perintah yang muncul (berisi `eyJ…`) → tempel utuh di halaman itu → **Aktifkan tunnel**.
3. Public Hostname: subdomain + domain Anda → Service **HTTP** `localhost` (atau `localhost:PORT` bila port web bukan 80).

Token disimpan di `data\cloudflared\token.txt` (hanya Administrator & SYSTEM); tunnel menyala sendiri setiap PC
dinyalakan sampai dimatikan dari halaman yang sama. Installer mengisi
`TRUSTED_PROXIES=127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16` (bila kosong / masih nilai lama `127.0.0.1,::1`)
supaya HTTPS & IP asli pengunjung (`CF-Connecting-IP`) terbaca — batas login dari internet tetap berlaku per pengunjung.

**Login gagal lewat domain tapi lokal aman** → halaman https memuat aset/Livewire lewat http & diblokir browser:
- Halaman Cloudflare Tunnel → baris "Proxy tepercaya" merah? Jalankan patch terbaru, atau isi nilai di atas di
  `C:\BillingPS\app\.env` lalu Pemeliharaan sistem → Optimalkan (atau `..\runtime\php\php.exe artisan optimize`).
- Cloudflare: Rocket Loader **Off**, Bot Fight Mode **Off**, Service tunnel **HTTP** (bukan HTTPS).

## Struktur folder

| Folder | Isi |
|---|---|
| `app` | aplikasi (+ `.env`) |
| `runtime` | php, apache, mariadb, node, cloudflared, nssm |
| `whatsapp` | layanan WhatsApp (Node + Baileys, `node_modules` siap pakai) |
| `data` | **database, foto/logo, sesi, backup** — jangan dihapus |
| `logs` | log pemasangan, apache, mariadb, layanan |
| `kelola` | skrip pengelola & `konfigurasi.json` |

## Delta Billing HuB Monitor

Ikon **Delta Billing HuB Monitor** di Desktop & menu Start (`kelola\BillingPS-Monitor.exe`, minta izin Administrator;
installer menutupnya otomatis sebelum update supaya file tidak terkunci).
Dipakai saat billing tidak bisa dibuka:

- **Layanan Windows**: status Database (MariaDB), Web (Apache + PHP), Realtime, Antrean, Jadwal, WhatsApp, Tunnel
  + tombol Nyalakan / Hentikan / Restart per layanan, Nyalakan semua, Restart semua (database dulu).
- **Kesehatan**: query MySQL sungguhan (versi + jumlah pengguna), PHP CLI (+ pdo_mysql), halaman web `/up`
  (Apache + PHP + Laravel, waktu respons), port realtime, ruang disk.
- **Port**: web, database, realtime, WhatsApp — terbuka / tidak & program yang memakainya (bila bentrok).
- **Backup database** tanpa aplikasi web: `mariadb-dump` → `data\storage\app\private\backup\backup-…-monitor.zip`
  (format sama dengan backup aplikasi, opsional + foto/logo). Database dinyalakan otomatis bila berhenti. Pulihkan
  lewat Admin → Backup atau installer "Pulihkan dari file backup" (trigger sinkron dipasang ulang otomatis).
- Segarkan otomatis tiap 10 detik, catatan aksi di bawah, tombol Buka Billing & Folder log.

Dibuat dari `installer\monitor\Monitor.cs` (C# WinForms, dikompilasi `build.ps1` dengan `csc` .NET Framework 4
bawaan Windows — tanpa SDK). Konversi manual: `BillingPS-Monitor.exe --konversi dump.sql database.sql`.

## Kelola sehari-hari

Menu Start → **Delta Billing HuB** (pemasangan lama: grup **Billing PS** tetap dipakai):
- **Delta Billing HuB Monitor** — lihat di atas.
- **Kelola layanan Delta Billing HuB** — status, nyalakan / hentikan / mulai ulang semua layanan, buka folder log.
- **Folder log Delta Billing HuB**, **Panel Admin**, **Uninstall**.

Pemeliharaan lain (cache, migrasi, trigger sync) ada di Admin → Pengaturan → **Pemeliharaan sistem**.

## Performa database & PHP

- **Index** tambahan untuk tabel yang cepat membesar: `sesi (cabang_id, mulai_pada)`, `audit_log (cabang_id, aksi, created_at)`,
  `transaksi_item (transaksi_id, jenis)`, `sync_antrean (tabel, row_id, aksi)`.
- **Antrean sinkron** tidak lagi diisi heartbeat TV (kolom status/ping `perangkat_tv` diabaikan trigger) dan baris
  ganda per data dirapikan — hanya perubahan terakhir yang dikirim.
- **`php artisan db:rapikan`** (jadwal tiap hari 03:40): hapus log lama bertahap per 5.000 baris — `log_tv` 120 hari,
  `notifikasi_log` 90, `sync_log` 30, `audit_log` 400, `failed_jobs` 30 — plus cache kedaluwarsa & antrean ganda.
  `--analisa` (Minggu 04:10) memperbarui statistik index (`ANALYZE TABLE`) tabel besar.
- **MariaDB** (`templat\my.ini`): `innodb_buffer_pool_size` ±20% RAM (256 MB–2 GB, dihitung ulang tiap update),
  log InnoDB 256 MB, performance_schema mati, query > 2 detik dicatat di `logs\mariadb-lambat.log`.
- **PHP OPcache** (`templat\php.ini`): 256 MB, 30.000 file, cek perubahan file tiap 30 detik.

## Update ke versi baru

Jalankan installer versi baru di PC yang sama. Wizard isian dilewati; otomatis:
backup database → hentikan layanan → ganti program → migrasi database → pasang ulang trigger sync →
daftarkan APK TV baru → nyalakan layanan. Data, `.env` & pengaturan tetap.
Pemasangan lama tanpa WhatsApp otomatis mendapat layanan `BillingPS-WhatsApp` + isian `WA_SERVICE_*` di `.env`.

## Uninstall

Panel Kontrol / menu Start → Uninstall Delta Billing HuB. Layanan & aturan firewall dihapus, lalu ditanya:
**hapus semua data?** Pilih **No** untuk menyimpan `C:\BillingPS\data` (database, foto, backup) —
saat dipasang ulang ke folder yang sama, data lama dipakai lagi.

## Membuat installer (PC pengembang)

```powershell
powershell -ExecutionPolicy Bypass -File installer\build.ps1 -Versi 2026.10.01
```

Hasil: `installer\keluaran\BillingPS-Setup-2026.10.01.exe`. Butuh: Inno Setup 6, Composer, Node.js (hanya untuk build aset),
dan folder PHP 8.4 **Thread Safe** (lokasi di `installer\bahan.json`, bawaan dari Laragon), serta `node.exe`
(Node 20.6+; lokasi `node` di `bahan.json`) + `whatsapp-service\node_modules` (bila belum ada: `npm ci --omit=dev`).
Apache (Apache Lounge VS18), MariaDB, NSSM, VC++ redist & cacert diunduh sekali ke `installer\bahan\unduhan`
(ganti versi lewat URL di `bahan.json`). Bangun APK TV dulu bila ingin ikut dibundel.

**Paket server cloud** (tanpa runtime Windows, untuk VPS / CloudPanel):

```powershell
powershell -ExecutionPolicy Bypass -File installer\build.ps1 -PaketCloud
```

Hasil: `installer\keluaran\BillingPS-cloud-<versi>.tar.gz` (aplikasi + `vendor` + aset). Langkah pasang: [cloudpanel.md](cloudpanel.md).

## Bila pemasangan gagal

- Lihat `C:\BillingPS\logs\pasang-*.log` (password tidak ditulis ke log).
- Port web dipakai program lain → jalankan installer lagi, pilih port lain (mis. 8000).
- Perbaiki masalahnya lalu jalankan installer lagi: langkah yang sudah berhasil (database, `.env`) tidak diulang.
- `nssm set BillingPS-Web AppParameters gagal` (installer 2026.10.03): bug PowerShell 5.1 membuang argumen kosong.
  Sudah diperbaiki sejak build 2026.10.04 — jalankan installer baru di atas pemasangan yang gagal.
- Tidak bisa login padahal email & password benar: akun owner dibuat di percobaan pertama, dan installer
  versi lama tidak mengganti password saat dipasang ulang (atau installer masuk mode update tanpa wizard).
  Reset password dari PowerShell **Administrator**:
  ```powershell
  cd C:\BillingPS\app
  ..\runtime\php\php.exe artisan tinker --execute="echo App\Models\User::withoutGlobalScopes()->pluck('email')->implode(', ');"
  ..\runtime\php\php.exe artisan tinker --execute="App\Models\User::withoutGlobalScopes()->where('email','EMAIL')->update(['password'=>bcrypt('PasswordBaru123')]);"
  ```
