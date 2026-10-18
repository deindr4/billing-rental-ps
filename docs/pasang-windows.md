# Panduan pasang Delta Billing HuB di Windows

Panduan untuk pemilik rental: dari PC kosong sampai TV terhubung. Satu file installer `.exe` — tidak perlu memasang
PHP, MySQL, XAMPP atau Node.js sendiri.

**Isi**: [Persiapan](#1-persiapan) · [Pasang](#2-pasang-aplikasi) · [Login pertama](#3-login-pertama) ·
[Tablet & HP kasir](#4-tablet--hp-kasir) · [Hubungkan TV](#5-hubungkan-tv) · [Pindah PC](#6-pindah-pc--pasang-ulang) ·
[Akses dari internet](#7-akses-dari-internet-opsional) · [Update](#8-update) · [Uninstall](#9-uninstall) ·
[Masalah umum](#10-masalah-umum)

---

## 1. Persiapan

| | Minimal | Disarankan |
|---|---|---|
| Sistem operasi | Windows 10 64-bit | Windows 10 / 11 64-bit, update terbaru |
| Prosesor | 2 core 64-bit | 4 core atau lebih |
| RAM | 4 GB | 8 GB |
| Penyimpanan | 3 GB kosong + ruang backup | **SSD**, 10 GB kosong |
| Jaringan | LAN satu jaringan dengan TV & tablet kasir | Kabel LAN + **IP tetap** |

Sebelum memasang:

- Login Windows dengan akun **Administrator**.
- **IP tetap untuk PC ini**: di pengaturan router buat *DHCP reservation* (mis. `192.168.1.10`).
  Kalau IP berubah-ubah, tablet & TV kehilangan alamat server.
- Matikan *Sleep* PC: Settings → System → Power → Sleep: **Never**.
- Opsional: di BIOS aktifkan *Restore on AC power loss / Power on* supaya PC menyala sendiri setelah listrik padam.
- Siapkan: nama rental, nama cabang, email & password owner, **PIN 6 angka**, daftar unit & harga, menu F&B.

## 2. Pasang aplikasi

±5–10 menit.

1. Unduh **`BillingPS-Setup-<versi>.exe`** dari
   [Releases](https://github.com/deindr4/billing-rental-ps/releases/latest).
2. Klik dua kali → **Yes** saat Windows meminta izin Administrator.
   Bila muncul *Windows protected your PC*: **More info → Run anyway**.
3. Isi wizard:

   | Halaman | Isi |
   |---|---|
   | Folder | Biarkan `C:\BillingPS` |
   | Jenis pemasangan | **Pasang baru** (data kosong). *Pulihkan dari file backup* hanya saat pindah PC — lihat [bab 6](#6-pindah-pc--pasang-ulang) |
   | Data rental | Nama rental & nama cabang (bisa diubah nanti) |
   | Akun owner | Nama, email, password (min. 8 karakter) & **PIN 6 angka** untuk menyetujui aksi penting |
   | Zona waktu | WIB / WITA / WIT |
   | Port web | Biarkan **80**. Ganti (mis. `8000`) hanya bila port 80 dipakai program lain |

4. Klik **Install**, tunggu tahap *Menyiapkan database, aplikasi & layanan* (beberapa menit).
5. Di akhir tampil **Data login**: email, password, PIN, akun super admin & **alamat aplikasi**
   (mis. `http://192.168.1.10`). **Foto atau catat** — password tidak disimpan di file mana pun.
6. Centang *Buka Delta Billing HuB sekarang* → **Finish**.

**Yang terpasang otomatis** sebagai layanan Windows (menyala sendiri setiap PC dinyalakan): web server, database,
realtime TV, antrean, jadwal (cek pembayaran QRIS, backup harian), WhatsApp. Firewall dibuka **hanya untuk jaringan
lokal**. Port database, realtime & WhatsApp dipilih otomatis — bila port bawaan dipakai program lain, dipakai port
berikutnya yang bebas.

Di Desktop & menu Start ada dua ikon:

- **Delta Billing HuB** — membuka aplikasi.
- **Delta Billing HuB Monitor** — dipakai saat halaman billing tidak bisa dibuka: status & tombol
  Nyalakan / Hentikan / Restart tiap layanan, cek kesehatan, port beserta program yang memakainya (bila bentrok),
  dan **backup database** walau billing mati.

> Bila antivirus memblokir pemasangan, izinkan installer lalu jalankan ulang — langkah yang sudah berhasil tidak diulang.

## 3. Login pertama

- Di PC itu: `http://localhost` (atau ikon Delta Billing HuB).
- Masuk dengan email & password owner dari langkah 2.
- Login pertama menampilkan sambutan berisi kontak pengembang & grup WhatsApp info pengembangan — **sekali saja**.
- Panduan bergambar A–Z (unit, paket harga, F&B, kasir, TV, transaksi pertama, tutup kas) tersedia di aplikasi:
  **`http://IP-PC/tutorial-windows`** (juga ada versi PDF di halaman itu).

Urutan pengaturan awal yang disarankan: tipe konsol → unit → paket & tarif → produk F&B → pengguna kasir →
Pengaturan Operasional.

## 4. Tablet & HP kasir

Sambungkan tablet / HP ke Wi-Fi yang sama dengan PC, buka browser (Chrome, Edge, Safari) → **`http://IP-PC`**
(mis. `http://192.168.1.10`, atau `http://192.168.1.10:8000` bila port web bukan 80). Login dengan akun kasir.
Tips: *Add to Home screen* supaya terbuka seperti aplikasi.

Struk: printer thermal Bluetooth 58 / 80 mm lewat aplikasi **RawBT** (Android).

## 5. Hubungkan TV

Opsional — tanpa TV Agent billing tetap jalan (mode Manual). Dengan TV Agent, TV terkunci di luar sesi, pindah
otomatis ke HDMI PS saat main, menampilkan timer & tagihan, dan bisa dikontrol dari kasir.

TV: **Android TV / Google TV 8.0+**, satu jaringan dengan PC. Sekali per TV, ±5 menit:

1. Di TV buka **Play Store** → pasang **Downloader** (by AFTVnews).
2. Izinkan Downloader memasang aplikasi: Settings → Apps → Special app access → **Install unknown apps** →
   Downloader → **Allow**.
3. Buka Downloader → ketik **`http://IP-PC/apk`** → Go → **Install**.
   (APK juga ada di [Releases](https://github.com/deindr4/billing-rental-ps/releases/latest).)
4. Buka **TV Agent** → isi alamat server (`http://IP-PC`) → muncul **kode 6 angka**.
5. Di kasir / PC: **Admin → Perangkat TV & PC → Pasangkan TV / PC** → masukkan kode → pilih unit.
6. Ubah unit itu ke mode **TV Agent**. Indikator *TV online* muncul di kartu unit kasir.

**Izin tambahan** (disarankan) — cek di Admin → Perangkat TV & PC → **Diagnostik** mana yang masih "Tidak":

| Diagnostik | Cara memberi izin |
|---|---|
| Izin tampil di atas (timer melayang) | Di TV Agent: Menu staf → Info & diagnostik → **Izin tampil di atas** |
| Jadi layar utama (Home) | Menu staf → Info & diagnostik → **Jadikan layar utama** → pilih TV Agent. Atau tekan Home di remote → TV Agent → **Always** |
| Izin pasang update | Settings → Apps → Special app access → Install unknown apps → TV Agent → **Allow** |

Bila TV tidak menawarkan pilihan izin di atas (sebagian Google TV), lewat adb dari komputer:

```
adb shell appops set id.rentalps.tvagent SYSTEM_ALERT_WINDOW allow
adb shell cmd role add-role-holder android.app.role.HOME id.rentalps.tvagent
```

Kembalikan layar utama bawaan: `adb shell cmd role remove-role-holder android.app.role.HOME id.rentalps.tvagent`

Setelah dipasangkan, update APK berikutnya dikirim dari server (Admin → Rilis APK TV → Push update); di TV cukup
tekan **Instal**.

## 6. Pindah PC / pasang ulang

1. Di PC lama: Admin → **Backup** → buat & unduh file `backup-….zip`
   (bila billing tidak bisa dibuka: Delta Billing HuB Monitor → **Backup database**).
2. Di PC baru: jalankan installer → **Pulihkan dari file backup** → pilih file itu. Data rental & akun owner diambil
   dari backup.
3. Isi ulang (karena kunci enkripsi PC baru berbeda): API key payment gateway, token Telegram, scan QR WhatsApp.
4. Bila IP PC berubah, ganti alamat server di setiap TV (Menu staf TV Agent).

## 7. Akses dari internet (opsional)

Cloudflare Tunnel sudah ikut terpasang tapi **mati** sampai diaktifkan. Owner: Admin → Pengaturan →
**Cloudflare Tunnel**:

1. [one.dash.cloudflare.com](https://one.dash.cloudflare.com) → Networks → Tunnels → **Create a tunnel** →
   Cloudflared → beri nama.
2. Pilih Windows, salin perintah yang muncul (berisi `eyJ…`) → tempel utuh di halaman itu → **Aktifkan tunnel**.
3. Public Hostname: subdomain + domain Anda → Service **HTTP** `localhost` (atau `localhost:PORT` bila port web bukan 80).

Di Cloudflare: Rocket Loader **Off**, Bot Fight Mode **Off**, Service tunnel **HTTP** (bukan HTTPS).

Ingin tanpa PC menyala terus, atau TV & kasir tetap jalan saat PC mati? Lihat **Delta Cloud** di
[README](../README.md#delta-cloud).

## 8. Update

Aplikasi memeriksa rilis baru tiap 6 jam; admin melihat catatan fitur & tombol unduh di
**Pengaturan → Pemeliharaan sistem → Update aplikasi**.

Jalankan installer versi baru di PC yang sama — wizard dilewati, otomatis: **backup database** → hentikan layanan →
ganti program → perbarui database → nyalakan layanan. Data, pengaturan & login tetap. APK TV baru ikut terdaftar dan
dikirim bertahap ke TV yang sedang tidak dipakai.

## 9. Uninstall

Panel Kontrol / menu Start → **Uninstall Delta Billing HuB**. Saat ditanya **hapus semua data?** pilih **No** untuk
menyimpan `C:\BillingPS\data` (database, foto, backup) — bila dipasang ulang ke folder yang sama, data lama dipakai lagi.

## 10. Masalah umum

| Masalah | Solusi |
|---|---|
| Installer berhenti: port web 80 dipakai program lain (IIS, XAMPP, Skype lama, VMware) | Jalankan installer lagi, pilih port lain (mis. `8000`), lalu buka `http://IP-PC:8000` |
| Pemasangan gagal di tengah | Lihat `C:\BillingPS\logs\pasang-*.log`, perbaiki, jalankan installer lagi — langkah yang sudah berhasil tidak diulang |
| Halaman billing tidak bisa dibuka | Buka **Delta Billing HuB Monitor** → lihat layanan mana yang mati → **Restart semua** |
| Tablet tidak bisa membuka `http://IP-PC` | Pastikan satu Wi-Fi / jaringan dengan PC; IP PC sesuai (lihat Dashboard kasir); PC tidak Sleep |
| TV "offline" | TV standby / Wi-Fi putus → tombol **Bangunkan** di kartu unit; cek alamat server di TV Agent sama dengan IP PC |
| Unit tidak muncul di kasir | Unit tidak aktif, tipe konsolnya PC (lihat menu *Rental PC*), atau filter status masih aktif |
| "Tarif belum diatur" di kartu unit | Belum ada paket **tarif per jam** untuk tipe konsol / kategori unit itu |
| Program lain (Steam, game launcher) di PC yang sama | Tidak bentrok port. Batasi kecepatan unduh di Steam supaya sinkron cloud & QRIS tidak telat |

Butuh bantuan: Telegram [t.me/deindr4](https://t.me/deindr4) ·
Grup WhatsApp info pengembangan: [gabung](https://chat.whatsapp.com/C5i4qG9Dkc41BKZYV6XjeF)
