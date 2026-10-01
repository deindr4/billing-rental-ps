# Pembayaran online & bayar mandiri di TV

## Alur pelanggan

1. Layar kunci TV menampilkan QR **"Scan untuk main"** + tarif per jam (hanya saat kas buka).
2. Pelanggan scan dengan kamera HP → halaman `/main/<token-unit>` → ketik nominal (atau pilih cepat).
   Waktu dihitung otomatis: `menit = nominal × 60 / tarif per jam` (dibulatkan ke bawah).
   Nominal yang **sama persis dengan harga paket** unit itu → mendapat paket tersebut.
3. QRIS nominal itu muncul di TV (berlaku 10 menit, bisa diatur). Bayar dengan aplikasi bank / e-wallet.
4. Pembayaran terdeteksi → sesi mulai otomatis + waktu pilih game, tagihan lunas (metode **QRIS online**).
5. Waktu habis → layar tagihan menampilkan QR **isi ulang** (menambah waktu sesi yang sama).
   Kasir tetap bisa tambah waktu manual & menerima tunai; tagihan menampilkan bagian yang sudah dibayar online.
6. Sesi bayar mandiri yang habis & lunas diselesaikan otomatis setelah X menit (unit kosong lagi).

Contoh tarif PS5 Rp16.000/jam: Rp8.000 = 30 menit · Rp12.000 = 45 menit · Rp16.000 = 1 jam · Rp30.000 = 1 jam 52 menit.

## Pengaturan

| Di mana | Isi |
|---|---|
| Admin → Pengaturan → **Pembayaran online** (Owner / Super Admin) | Pilih gateway, mode sandbox, kunci API, tombol **Tes koneksi**. Kunci rahasia disimpan terenkripsi |
| Admin → Pengaturan → **Operasional** → Bayar mandiri di TV (per cabang) | Aktif/nonaktif, minimal menit (30), masa berlaku QRIS (10 menit), selesai otomatis (10 menit) |

## Gateway

| Gateway | Isian | URL notifikasi | Catatan |
|---|---|---|---|
| **Simulasi** | – | – | Uji tanpa akun: tandai lunas dari kasir → Pembayaran online → Simulasi bayar. Di server produksi hanya Owner |
| **Tripay** | Kode merchant, API key, private key, kanal QRIS | isi di dashboard Tripay | QRIS closed payment |
| **Midtrans** | Server key, penerbit (GoPay / ShopeePay) | Settings → Payment → Notification URL | Core API `qris` |
| **Duitku** | Kode merchant, API key, metode QRIS | dikirim otomatis per tagihan | |
| **iPaymu** | Nomor VA, API key | dikirim otomatis per tagihan | Notifikasi selalu dicek ulang ke API iPaymu |
| **DOKU** | Client ID, secret key, metode | Integration → Notification | DOKU Checkout: TV menampilkan QR **tautan** bayar, HP mendapat tombol "Bayar sekarang" |
| **Winpay** | – | – | Menunggu akun Winpay |

URL notifikasi: `https://<domain>/api/gateway/<provider>/callback` (ditampilkan di halaman pengaturan).
Biaya gateway ditanggung rental (tercatat di kolom biaya, tidak mengurangi waktu pelanggan).

**Status driver:** semua driver ditulis dari dokumentasi resmi & diuji dengan respons tiruan.
Belum diuji ke sandbox asli — lakukan "Tes koneksi" + satu transaksi sandbox setelah akun dibuat.

## Tanpa server publik

Server lokal **mengecek status ke gateway sendiri** tiap ±4 detik (selama QRIS menunggu) & tiap menit (scheduler),
jadi pembayaran tetap terdeteksi selama PC terhubung internet — callback tidak wajib.
Tapi halaman HP (`/main/...`) hanya bisa dibuka dari Wi-Fi rental kecuali ada alamat publik (lihat `docs/server-publik.md`).

## Keamanan

- Callback diperiksa tanda tangannya (Tripay HMAC, Midtrans SHA-512, Duitku MD5, DOKU HMAC); iPaymu dicek ulang ke API.
- Satu tagihan tidak bisa diproses dua kali walau callback & pengecekan berkala datang bersamaan.
- ID sesi diturunkan dari nomor tagihan → server lokal & cloud tidak membuat sesi ganda.
- Bila unit keburu dipakai / kas tutup saat uang masuk → status **Perlu tindakan**, muncul di kasir → Pembayaran online.

## Menu kasir: Pembayaran online

Daftar tagihan QRIS (menunggu / selesai / kedaluwarsa / perlu tindakan), tombol **Mulai di unit ini**
& **Sudah diurus** untuk yang perlu tindakan, dan **Simulasi bayar** (gateway Simulasi).
