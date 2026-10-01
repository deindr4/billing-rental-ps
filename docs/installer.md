# Installer Windows (tahap 14)

Satu file `BillingPS-Setup-<versi>.exe` untuk PC rental yang masih bersih (Windows 10/11 64-bit).
Isi: PHP 8.4, Apache 2.4.68, MariaDB 10.11 LTS, Visual C++ runtime 14.50+, aplikasi siap pakai, APK TV. Tanpa Node.js.

## Memasang di PC rental

1. Klik dua kali `BillingPS-Setup-….exe` (minta izin Administrator).
2. Wizard:
   - **Folder** — bawaan `C:\BillingPS`.
   - **Data rental** — nama rental & cabang.
   - **Zona waktu** — WIB / WITA / WIT.
   - **Akun owner** — nama, email, password (min. 8), PIN 4–6 angka.
   - **Port web** — biarkan 80 kecuali dipakai program lain.
3. Tunggu "Menyiapkan database, aplikasi & layanan" (beberapa menit). Halaman akhir menampilkan alamat aplikasi,
   misalnya `http://192.168.1.10` — buka dari tablet kasir & TV di Wi-Fi yang sama.
4. Ikon **Billing PS** di Desktop membuka aplikasi di PC itu.

Akun yang dibuat:

| Akun | Login | Password |
|---|---|---|
| Owner | email di wizard | password di wizard |
| Super admin | `superadmin@billing.lokal` | sama dengan owner — ganti: `php artisan superadmin superadmin@billing.lokal` |

Yang dipasang otomatis:

- **Layanan Windows** (menyala sendiri saat PC dinyalakan): `BillingPS-Database`, `BillingPS-Web`,
  `BillingPS-Realtime` (TV), `BillingPS-Antrean`, `BillingPS-Jadwal` (cek pembayaran QRIS, sync, backup harian).
- **Firewall**: port web & realtime TV (8080) dibuka **hanya untuk jaringan lokal**.
- Database dengan kata sandi acak (tersimpan di `C:\BillingPS\kelola\konfigurasi.json`, hanya Administrator).
- APK TV terbaru terdaftar sebagai rilis → di TV buka `http://IP-PC/apk`.

## Struktur folder

| Folder | Isi |
|---|---|
| `app` | aplikasi (+ `.env`) |
| `runtime` | php, apache, mariadb, nssm |
| `data` | **database, foto/logo, sesi, backup** — jangan dihapus |
| `logs` | log pemasangan, apache, mariadb, layanan |
| `kelola` | skrip pengelola & `konfigurasi.json` |

## Kelola sehari-hari

Menu Start → **Billing PS**:
- **Kelola layanan Billing PS** — status, nyalakan / hentikan / mulai ulang semua layanan, buka folder log.
- **Folder log Billing PS**, **Panel Admin**, **Uninstall**.

Pemeliharaan lain (cache, migrasi, trigger sync) ada di Admin → Pengaturan → **Pemeliharaan sistem**.

## Update ke versi baru

Jalankan installer versi baru di PC yang sama. Wizard isian dilewati; otomatis:
backup database → hentikan layanan → ganti program → migrasi database → pasang ulang trigger sync →
daftarkan APK TV baru → nyalakan layanan. Data, `.env` & pengaturan tetap.

## Uninstall

Panel Kontrol / menu Start → Uninstall Billing PS. Layanan & aturan firewall dihapus, lalu ditanya:
**hapus semua data?** Pilih **No** untuk menyimpan `C:\BillingPS\data` (database, foto, backup) —
saat dipasang ulang ke folder yang sama, data lama dipakai lagi.

## Membuat installer (PC pengembang)

```powershell
powershell -ExecutionPolicy Bypass -File installer\build.ps1 -Versi 2026.10.01
```

Hasil: `installer\keluaran\BillingPS-Setup-2026.10.01.exe`. Butuh: Inno Setup 6, Composer, Node.js (hanya untuk build aset),
dan folder PHP 8.4 **Thread Safe** (lokasi di `installer\bahan.json`, bawaan dari Laragon).
Apache (Apache Lounge VS18), MariaDB, NSSM, VC++ redist & cacert diunduh sekali ke `installer\bahan\unduhan`
(ganti versi lewat URL di `bahan.json`). Bangun APK TV dulu bila ingin ikut dibundel.

## Bila pemasangan gagal

- Lihat `C:\BillingPS\logs\pasang-*.log` (password tidak ditulis ke log).
- Port web dipakai program lain → jalankan installer lagi, pilih port lain (mis. 8000).
- Perbaiki masalahnya lalu jalankan installer lagi: langkah yang sudah berhasil (database, `.env`) tidak diulang.
