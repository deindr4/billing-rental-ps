<div align="center">

# 🎮 Delta Billing HuB

<sub>dulu: Billing Rental PS</sub>

**Aplikasi billing rental PlayStation & PC lengkap — kasir, TV terkunci otomatis, F&B, booking, member, turnamen, laporan & analisa.**
Jalan di PC rental (offline-first) dan bisa disinkron ke server cloud (Delta Cloud).

[![Rilis terbaru](https://img.shields.io/github/v/release/deindr4/billing-rental-ps?label=rilis&color=0ea5e9)](https://github.com/deindr4/billing-rental-ps/releases/latest)
![Windows](https://img.shields.io/badge/Installer-Windows%2010%2F11-0078D4?logo=windows&logoColor=white)
![Android TV](https://img.shields.io/badge/TV%20Agent-Android%20TV%208%2B-3DDC84?logo=android&logoColor=white)

**[⬇️ Unduh rilis terbaru (EXE & APK TV)](https://github.com/deindr4/billing-rental-ps/releases/latest)**

<img src="docs/gambar/kasir-matriks-gelap.webp" alt="Matriks unit kasir" width="100%">

</div>

---

## Daftar isi

- [Kenapa aplikasi ini](#kenapa-aplikasi-ini)
- [Tampilan](#tampilan)
- [Fitur](#fitur)
- [Kebutuhan sistem](#kebutuhan-sistem)
- [Memasang](#memasang)
- [Update](#update)
- [Delta Cloud](#delta-cloud)
- [Kontak](#kontak)

---

## Kenapa aplikasi ini

| Masalah di rental | Solusi |
|---|---|
| Pelanggan main lewat waktu, kasir lupa mematikan | **TV terkunci sendiri** saat waktu habis — dihitung di TV, tetap jalan walau server / internet putus |
| Catatan manual, uang laci tidak cocok | Semua sesi, F&B & pembayaran tercatat per **shift kas**; selisih kas, pembatalan & aksi penting masuk **log audit** |
| Harus ke kasir untuk tambah waktu | Pelanggan **scan QRIS di TV**, bayar sendiri, TV terbuka otomatis |
| PC rental mati = semua berhenti | TV pindah otomatis ke **server cloud** & kembali ke lokal saat hidup lagi |
| Pasang server itu ribet | **Satu installer `.exe`** — semua komponen ikut terpasang sebagai layanan Windows |

---

## Tampilan

### Kasir (tablet / PC / HP)

<table>
  <tr>
    <td width="50%"><img src="docs/gambar/kasir-dashboard.webp" alt="Dashboard kasir"><br><sub><b>Dashboard</b> — pendapatan & laba hari ini, alamat server, status cloud & sinkron, TV / PC online, unit siap</sub></td>
    <td width="50%"><img src="docs/gambar/kasir-kelola-sesi.webp" alt="Kelola sesi"><br><sub><b>Kelola sesi</b> — tambah / kurangi waktu, F&B, pause, pindah unit, bonus waktu, struk</sub></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/kasir-kurangi-waktu.webp" alt="Kurangi waktu"><br><sub><b>Kurangi waktu</b> — dipesan 2 jam ternyata 1 jam: harga dihitung proporsional, kembalian tunai dari laci</sub></td>
    <td><img src="docs/gambar/kasir-pindah-unit.webp" alt="Pindah unit"><br><sub><b>Pindah unit + konversi tarif</b> — PS5 ↔ PS4, sisa waktu tetap, tagihan disesuaikan otomatis</sub></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/kasir-unit-dibersihkan.webp" alt="Unit dibersihkan"><br><sub><b>Sedang dibersihkan</b> — setelah selesai & lunas unit dibersihkan dulu (manual / otomatis N menit) sebelum siap lagi</sub></td>
    <td><img src="docs/gambar/kasir-pos.webp" alt="POS F&B"><br><sub><b>POS F&B</b> — bundling otomatis (beli 3 = Rp2.500), gabung ke tagihan unit, batalkan salah order</sub></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/kasir-stok-masuk.webp" alt="Stok masuk"><br><sub><b>Stok masuk dari supplier</b> — nota pembelian, harga pokok baru, belanja tunai dari hasil bulan lalu</sub></td>
    <td><img src="docs/gambar/kasir-tes-stik.webp" alt="Tes stik"><br><sub><b>Tes Stik</b> — tombol, analog drift, L2/R2, getar, gyro & speaker DualSense langsung di browser</sub></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/kasir-rental-daftar.webp" alt="Tampilan daftar"><br><sub><b>Tampilan daftar</b> — satu baris per unit (timer, tagihan, aksi, remote), 15 unit per halaman; bisa ganti ke kotak</sub></td>
    <td><img src="docs/gambar/kasir-playbox.webp" alt="Sewa Playbox"><br><sub><b>Sewa Playbox</b> — PS bawa pulang: jatuh tempo, telat, deposit, perpanjang, kembali, surat sewa, WA & lokasi Maps</sub></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/kasir-playbox-baru.webp" alt="Sewa Playbox baru"><br><sub><b>Sewa Playbox baru</b> — 4 langkah: penyewa (foto, KTP, lokasi) → unit & durasi → jaminan & checklist → tanda tangan</sub></td>
    <td><img src="docs/gambar/kasir-rental-pc.webp" alt="Rental PC"><br><sub><b>Rental PC</b> — menu terpisah dari Rental PS; tutup game hang, Task Manager sementara, kunci, log off, restart, nyalakan (Wake-on-LAN)</sub></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/kasir-analisa-pintar.webp" alt="Analisa Pintar"><br><sub><b>Analisa Pintar</b> — audit kecurangan kasir, titik impas, proyeksi akhir bulan, operasional & stok (tanpa AI, offline)</sub></td>
    <td><img src="docs/gambar/kasir-kelola-tv.webp" alt="Kelola TV"><br><sub><b>Kelola TV</b> — unlock / lock, bypass berdurasi, kode darurat, riwayat</sub></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/kasir-turnamen-bagan.webp" alt="Turnamen"><br><sub><b>Turnamen</b> — gugur, gugur ganda, liga, fase grup + klasemen</sub></td>
    <td><img src="docs/gambar/kasir-sembunyi-nominal.webp" alt="Sembunyikan nominal"><br><sub><b>Tombol mata</b> — omzet, kas laci & laporan jadi <code>Rp *******</code> sebelum foto layar</sub></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/kasir-matriks-terang.webp" alt="Mode terang"><br><sub>Mode terang · ikon & tombol berwarna per fungsi</sub></td>
    <td align="center"><img src="docs/gambar/kasir-hp.webp" alt="Kasir di HP" width="70%"><br><sub>Responsif di HP: matriks unit & POS</sub></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/kasir-lonceng.webp" alt="Lonceng notifikasi"><br><sub><b>Lonceng notifikasi</b> — Playbox telat, pembatalan, booking online, selisih kas, stok, keamanan & sistem; klik → halaman terkait</sub></td>
    <td><img src="docs/gambar/kasir-notifikasi.webp" alt="Riwayat notifikasi"><br><sub><b>Riwayat notifikasi</b> — 90 hari, filter kelompok / tingkat / belum dibaca; yang penting juga ke Telegram / WA</sub></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/kasir-lisensi.webp" alt="Lisensi"><br><sub><b>Lisensi & kontak</b> — kontak pengembang (Telegram), grup WhatsApp info pengembangan</sub></td>
    <td><img src="docs/gambar/kasir-sambutan-lisensi.webp" alt="Sambutan pertama"><br><sub><b>Sambutan pertama</b> — setelah pasang baru di Windows, info lisensi + Telegram & grup WhatsApp tampil <b>sekali</b></sub></td>
  </tr>
</table>

### Delta Billing HuB Monitor (PC rental Windows)

<p align="center">
  <img src="docs/gambar/monitor.webp" alt="Delta Billing HuB Monitor" width="85%"><br>
  <sub>Program pendamping yang ikut installer — ikon <b>Delta Billing HuB Monitor</b> di Desktop & menu Start</sub>
</p>

Dipakai saat halaman billing tidak bisa dibuka atau terasa bermasalah — tanpa perlu membuka browser, PowerShell, atau services.msc:
status & restart tiap layanan (database, web, realtime TV, antrean, jadwal, WhatsApp, Cloudflare Tunnel), cek kesehatan,
port yang dipakai, dan **backup database** walau billing mati.

### TV (APK TV Agent untuk Android TV / Google TV)

<table>
  <tr>
    <td width="50%"><img src="docs/gambar/tv-layar-kunci.webp" alt="Layar kunci"><br><sub><b>Layar kunci</b> — logo & wallpaper rental, QRIS "scan untuk main", running text, kontak pengembang</sub></td>
    <td width="50%"><img src="docs/gambar/tv-tagihan.webp" alt="Tagihan di TV"><br><sub><b>Waktu habis</b> — rincian tagihan, QR isi ulang waktu dari HP, tombol panggil kasir</sub></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/tv-dibersihkan.webp" alt="Sedang dibersihkan"><br><sub><b>Sedang dibersihkan</b> — TV tetap terkunci sampai kasir menandai unit siap</sub></td>
    <td><img src="docs/gambar/tv-pemberitahuan.webp" alt="Pemberitahuan"><br><sub><b>Pemberitahuan</b> dari kasir di tengah layar + running text promo</sub></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/tv-transisi-logo.webp" alt="Transisi logo"><br><sub><b>Transisi logo</b> saat mulai & selesai main</sub></td>
    <td></td>
  </tr>
</table>

<p align="center">
  <img src="docs/gambar/tv-timer.webp" alt="Timer melayang" width="75%"><br>
  <sub><b>Timer melayang di atas game</b> — warna, ukuran & kepekatan diatur admin; otomatis merah & berkedip + bunyi saat hampir habis</sub>
</p>

### Admin & halaman publik

<table>
  <tr>
    <td width="50%"><img src="docs/gambar/admin-dasbor.webp" alt="Dasbor admin"><br><sub><b>Dasbor</b> — status server, database, sync, antrean, backup, TV & versi aplikasi</sub></td>
    <td width="50%"><img src="docs/gambar/admin-laporan.webp" alt="Laporan"><br><sub><b>Laporan</b> — omzet, laba, HPP, metode bayar, perbandingan cabang</sub></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/admin-perangkat.webp" alt="Perangkat TV & PC"><br><sub><b>Perangkat TV & PC</b> — statistik tipe TV, versi Android & APK, status online, push update</sub></td>
    <td><img src="docs/gambar/admin-neraca.webp" alt="Neraca"><br><sub><b>Neraca</b> — aset, kewajiban & ekuitas per tanggal dengan pembanding</sub></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/admin-laporan-pembelian.webp" alt="Laporan pembelian"><br><sub><b>Pembelian</b> — per supplier & produk, dana belanja dari hasil bulan lalu</sub></td>
    <td><img src="docs/gambar/admin-selisih-harga.webp" alt="Selisih harga pokok"><br><sub><b>Selisih harga pokok</b> — bulan ini vs bulan lalu, supplier termurah per produk</sub></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/admin-laba-penjualan.webp" alt="Laba penjualan"><br><sub><b>Laba penjualan F&B</b> — per produk & kategori, tanda produk rugi / margin tipis</sub></td>
    <td><img src="docs/gambar/admin-bundling.webp" alt="Bundling"><br><sub><b>Bundling</b> — "beli N = Rp X" per produk, dengan masa berlaku</sub></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/admin-supplier.webp" alt="Supplier"><br><sub><b>Supplier</b> — kontak, catatan & riwayat pembelian</sub></td>
    <td></td>
  </tr>
  <tr>
    <td><img src="docs/gambar/publik-billboard.webp" alt="Billboard"><br><sub><b>Billboard</b> — PlayStation & PC terpisah, tampilan Grid / List, slider iklan, turnamen di atas</sub></td>
    <td align="center"><img src="docs/gambar/publik-bayar-mandiri.webp" alt="Bayar mandiri" width="70%"><br><sub><b>Bayar mandiri</b> dari HP pelanggan → QRIS tampil di TV</sub></td>
  </tr>
</table>

---

## Fitur

<details open>
<summary><b>🕹️ Rental & sesi</b></summary>

- Matriks unit realtime: ready, terisi, menunggu bayar, maintenance — sisa waktu, tagihan & status TV per kartu (kotak / daftar).
- Mode **paket / durasi** (hitung mundur) dan **open billing** (blok menit, minimal tagih, toleransi, pembulatan).
- **Waktu pilih game** tidak ditagih, tambah waktu, **kurangi waktu** (harga proporsional), **bonus waktu**, pause & resume, batal tambah waktu & batal sesi.
- **Pindah unit dengan konversi tarif** (PS5 ↔ PS4): sisa waktu tetap, tagihan disesuaikan.
- Status **Sedang dibersihkan** setelah sesi selesai & lunas (aktif per cabang, manual atau otomatis).
- Satu TV beberapa konsol (nama per **HDMI**), tarif per tipe konsol, kategori unit (VIP / reguler), paket & aturan harga.
- **Rental PC** di menu terpisah, member & laporan tetap satu.
</details>

<details>
<summary><b>📺 TV Agent (APK Android TV)</b></summary>

- TV **terkunci** di luar sesi; saat main pindah otomatis ke HDMI PS, saat habis kembali terkunci.
- Timer dihitung **di TV** (aman saat offline), peringatan sisa waktu: angka merah, berkedip, bunyi.
- Remote dari kasir: volume, matikan / bangunkan layar, restart, pindah HDMI, pemberitahuan, running text.
- **Bypass** berdurasi dengan PIN, **kode darurat** offline, akses staf.
- **Failover** lokal ↔ cloud, mode kiosk, update APK dari server (push ke semua TV, rollback).
</details>

<details>
<summary><b>🍜 POS, stok, keuangan & karyawan</b></summary>

- POS F&B: jual langsung atau **gabung ke tagihan unit**, scan barcode, batal salah order, **bundling** (beli N = Rp X).
- Stok per cabang dengan HPP rata-rata, stok masuk dari **supplier** + nota, **opname**, mutasi.
- **Laporan pembelian, selisih harga pokok, laba penjualan F&B & neraca** (Owner & Admin).
- **Shift kas** satu laci per cabang + **serah terima**, selisih kas tercatat, pengeluaran dengan PIN.
- **Karyawan**: jadwal, **absen PIN + selfie**, **rekap gaji** & bonus, slip gaji.
- **Sewa Playbox** (PS bawa pulang): penyewa + lokasi Maps, foto & KTP privat, tarif jam/hari/minggu/bulan, jaminan, checklist, surat sewa, denda, pengingat WA.
- Pembayaran tunai, QRIS, transfer, saldo member, campuran; **bayar sekaligus** beberapa tagihan; struk Bluetooth & WhatsApp.
- **Dashboard utama**, **ringkasan harian ke owner** (Telegram / WhatsApp), **Laporan Detail** & ekspor CSV.
- **Menu Pajak**: PBJT hiburan & makan-minum per cabang, PPh Final UMKM 0,5% — [panduan](docs/pajak.md).
- **Mitra & Bagi Hasil**: modal patungan, laba dibagi sesuai porsi modal, laporan PDF / WhatsApp.
- **Tes Stik**: tombol, analog drift, L2/R2, getar, gyro & speaker DualSense — langsung di browser.
- **Tombol mata**: sembunyikan nominal sebelum foto layar.
</details>

<details>
<summary><b>📊 Analisa pintar (tanpa AI, offline)</b></summary>

- **Audit kecurangan kasir**: skor risiko 0–100 dari pembatalan, kas kurang, bonus waktu, bypass TV, batal F&B, diskon, PIN salah.
- **Keuangan**: vs periode lalu, titik impas harian, proyeksi akhir bulan, margin, ROI aset.
- **Operasional & stok**: utilisasi per unit, jam sepi, paket terlaris, barang hilang saat opname, stok mati.
</details>

<details>
<summary><b>👥 Member, booking, turnamen & notifikasi</b></summary>

- Member: saldo top-up, **poin**, **stamp**, **tier** dengan diskon otomatis.
- **Booking online** dari HP, antrean lounge, **turnamen** (gugur, gugur ganda, liga, fase grup) dengan bagan publik.
- **Billboard** publik: status stasiun, turnamen, slider iklan.
- **Lonceng notifikasi** (Playbox telat, pembatalan, booking, selisih kas, stok, keamanan, sistem); yang penting juga ke Telegram / WA.
</details>

<details>
<summary><b>💳 Pembayaran online, keamanan & sistem</b></summary>

- Payment gateway: **Tripay, Midtrans, Duitku, iPaymu, DOKU, Winpay** — pelanggan bayar QRIS dari TV / HP, **TV terbuka otomatis**.
- Multi-cabang; role **Owner, Supervisor, Kasir, Teknisi** + PIN persetujuan; log audit & anomali; sudah melalui pentest.
- Backup harian otomatis + pulihkan; **otomatis ke Google Drive** pemilik — [panduan](docs/backup-google-drive.md).
- **Cloudflare Tunnel** dari admin, laporan ke **Telegram** & **WhatsApp**, **cek update** otomatis.
</details>

---

## Kebutuhan sistem

### 🪟 PC rental (installer `.exe`)

| | Minimal | Disarankan |
|---|---|---|
| Sistem operasi | Windows 10 64-bit | Windows 10 / 11 64-bit, update terbaru |
| Prosesor | 2 core 64-bit | 4 core atau lebih |
| RAM | 4 GB | 8 GB |
| Penyimpanan | 3 GB kosong + ruang backup harian | **SSD**, 10 GB kosong |
| Jaringan | LAN satu jaringan dengan TV & tablet kasir | Kabel LAN + **IP tetap** (reservasi DHCP di router) |
| Akses | Akun **administrator** Windows untuk memasang | PC menyala selama jam buka |
| Internet | Tidak wajib untuk kasir & TV | Untuk cloud, pembayaran online, WhatsApp, cek update |

Semua komponen **sudah termasuk** di installer (web server, database, layanan WhatsApp, Cloudflare Tunnel) dan
terpasang sebagai layanan Windows. Firewall hanya dibuka untuk jaringan lokal.

### Perangkat lain

| Perangkat | Kebutuhan |
|---|---|
| 📺 TV | **Android TV / Google TV 8.0+** (Xiaomi, TCL, dll. — sudah diuji Android TV 12 & 14), satu jaringan dengan PC rental |
| 📱 Kasir | Tablet / PC / HP dengan browser modern (Chrome, Edge, Safari) |
| 🖨️ Struk | Printer thermal Bluetooth 58 / 80 mm lewat aplikasi **RawBT** (Android) |
| 💬 WhatsApp | Nomor WhatsApp khusus rental (login QR di admin) |

---

## Memasang

### PC rental (Windows 10 / 11, 64-bit)

1. Unduh **`BillingPS-Setup-<versi>.exe`** dari [Releases](https://github.com/deindr4/billing-rental-ps/releases/latest).
2. Jalankan → pilih **Pasang baru** atau **Pulihkan dari file backup** (pindah PC).
3. Isi nama rental, akun owner, zona waktu → tunggu beberapa menit.
4. Selesai: **data login** ditampilkan, aplikasi terbuka di `http://localhost` dan `http://IP-PC` untuk tablet & TV.

Panduan bergambar A–Z (unit, paket, F&B, kasir, TV, transaksi pertama) tersedia di aplikasi: `http://IP-PC/tutorial-windows`.

### TV

Di TV buka `http://IP-PC/apk` (atau unduh **`tv-agent-<versi>.apk`** dari [Releases](https://github.com/deindr4/billing-rental-ps/releases/latest))
→ pasang **TV Agent** → isi alamat server → masukkan kode pairing di Admin → Perangkat TV & PC.

---

## Update

Setiap pemasangan memeriksa [rilis terbaru](https://github.com/deindr4/billing-rental-ps/releases) tiap 6 jam.
Bila ada versi baru, admin melihat catatan fiturnya di **Pemeliharaan sistem → Update aplikasi** beserta tombol unduh
file `.exe`. Installer otomatis backup database sebelum update — data & pengaturan tetap.

---

## Delta Cloud

**Satu server cloud khusus untuk setiap rental** (beserta cabangnya), disiapkan & dikelola pengembang lewat langganan:
sinkron & cadangan cloud (TV & kasir tetap jalan saat PC rental mati), billboard & booking online, akses owner dari mana saja,
dan backup otomatis ke Google Drive.
Pesan langsung dari aplikasi: **Admin → Pengaturan → Langganan Delta Cloud** — bayar QRIS, sinkron cloud terpasang otomatis.

---

## Kontak

- Telegram: [t.me/deindr4](https://t.me/deindr4)
- Grup WhatsApp info pengembangan: [gabung](https://chat.whatsapp.com/C5i4qG9Dkc41BKZYV6XjeF)

Copyright © [deindr4](https://github.com/deindr4)
