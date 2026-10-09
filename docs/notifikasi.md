# Lonceng notifikasi

Ikon **lonceng** di pojok kanan atas aplikasi kasir (tablet, PC, HP) dan panel admin. Angka merah = jumlah yang belum
dibaca. Klik lonceng → 12 notifikasi terbaru; klik satu notifikasi → langsung ke halaman terkait (Playbox, Transaksi,
Jadwal & Booking, Stok, ...) dan tertanda dibaca. **Lihat semua notifikasi** (`/notifikasi`) → riwayat 90 hari dengan
filter kelompok, tingkat & "belum dibaca saja". Tombol **Tandai semua dibaca** di keduanya.

Tujuannya supaya kejadian penting tidak terlewat walau admin lupa membuka riwayat.

## Tingkat

| Tingkat | Tampilan | Diteruskan ke Telegram / WhatsApp |
|---|---|---|
| **Penting** | ikon merah + label PENTING, muncul sebagai pesan pop-up | ✅ (bisa dipilih per jenis) |
| **Peringatan** | ikon oranye, muncul sebagai pesan pop-up | – |
| **Info** | ikon biru | – |

Lonceng diperiksa tiap 30 detik (kasir) / 60 detik (admin). Notifikasi penting & peringatan yang baru masuk tampil
sebagai pesan pop-up kecil di aplikasi kasir.

## Jenis notifikasi & penerima

Penerima mengikuti **izin role** ([hak-akses.md](hak-akses.md)) dan cabang yang sedang dibuka (panel admin: semua cabang
miliknya). Pelaku **tidak** diberi tahu kejadiannya sendiri (mis. kasir yang membatalkan transaksi), kecuali login / PIN
gagal (akunnya mungkin sedang dicoba orang lain).

| Kelompok | Notifikasi | Tingkat | Izin penerima | Sumber |
|---|---|---|---|---|
| Sewa Playbox | Sewa baru, diperpanjang, dikembalikan | Info | `playbox.kelola` | saat terjadi |
| | Kembali dengan denda / kerusakan, sewa dibatalkan | Peringatan | `playbox.kelola` | saat terjadi |
| | Segera jatuh tempo (≤ 3 jam) | Peringatan | `playbox.kelola` | jadwal 5 menit |
| | **Telat belum kembali** (diulang sekali per hari) | Penting | `playbox.kelola` | jadwal 5 menit |
| | Disewakan ke penyewa daftar hitam | Penting | `playbox.kelola` | saat terjadi |
| Pembatalan & koreksi | **Transaksi dibatalkan** | Penting | `transaksi.batal` | log audit |
| | Batal F&B, tambah waktu, aksesori | Peringatan | `transaksi.batal` | log audit |
| | Batal pengeluaran | Peringatan | `pengeluaran.batal` | log audit |
| | Batal pembayaran gaji | Peringatan | `karyawan.kelola` | log audit |
| | Waktu gratis diberikan | Peringatan | `sesi.gratis` | log audit |
| | Koreksi saldo / poin member | Peringatan | `member.koreksi` | log audit |
| | Koreksi HPP | Info | `laporan.laba` | log audit |
| Booking | Booking online baru | Info | `rental.kelola` | saat terjadi |
| | Booking mulai 15 menit lagi | Info | `rental.kelola` | jadwal 5 menit |
| | Booking tidak datang | Peringatan | `rental.kelola` | jadwal 5 menit |
| Uang & kas | **Selisih kas** saat tutup kas / serah terima | Penting | `laporan.lihat` | log audit |
| | Bayar mandiri perlu tindakan kasir | Peringatan | `pembayaran.terima` | log audit |
| Stok | Stok habis (peringatan) / menipis ≤ minimum (info), sekali per produk per hari | – | `stok.lihat` | jadwal 5 menit |
| Keamanan | **Login gagal** 5× dalam 15 menit dari alamat yang sama | Penting | `audit.lihat` | log audit |
| | PIN salah 3× dalam 10 menit | Peringatan | `audit.lihat` | log audit |
| | Kode darurat TV dipakai | Peringatan | `audit.lihat` | log audit |
| | TV di-bypass | Info | `audit.lihat` | log audit |
| Sistem | **Backup otomatis tidak berjalan** (> 36 jam), **database dipulihkan** | Penting | `admin.pengaturan` | jadwal / log audit |
| | Sinkron cloud macet (> 30 menit), antrean tugas macet (> 10 menit), disk < 5 GB | Peringatan | `admin.pengaturan` | jadwal 5 menit |
| | Versi baru aplikasi tersedia | Info | `admin.pengaturan` | jadwal 5 menit |
| Karyawan & unit | Shift belum ditutup > 12 jam | Peringatan | `laporan.lihat` | jadwal 5 menit |
| | TV / agen PC offline saat sesi berjalan (> 5 menit) | Peringatan | `rental.kelola` | jadwal 5 menit |
| | Unit masuk maintenance | Info | `maintenance.kelola` | saat terjadi |

Owner memiliki semua izin → menerima semuanya. Kasir bawaan menerima Playbox, booking, stok, TV offline & bayar mandiri.

## Teruskan ke Telegram / WhatsApp

Admin → Pengaturan → **Notifikasi & Laporan** → bagian **Lonceng notifikasi**: centang jenis penting yang juga dikirim
ke grup Telegram / WhatsApp cabang (bawaan: semua). Kosongkan semua untuk lonceng saja. Memakai bot / nomor WhatsApp
yang sama dengan laporan tutup kas; pesan masuk antrean & dicoba ulang bila internet putus (riwayat di halaman yang sama).

Contoh pesan:

```
🔴 PENTING · BOX-02 telat · Kadek Surya
Kadek Surya (6281234567803) · jatuh tempo 09/10 12:25 (10 jam yang lalu) · SWB-DGH1-L-20261009-0004
📍 Cabang Utama · 09/10/2026 22:49
```

## Teknis

- Tabel `notifikasi` (hanya ditambah, ikut [sinkron](sinkron.md) lokal ↔ cloud), `notifikasi_baca` & `notifikasi_pengguna`
  (status baca per pengguna, per server). Kolom `kunci` unik per rental mencegah notifikasi ganda — juga bila lokal &
  cloud membuat kejadian yang sama.
- Kode: `App\Services\Notifikasi\Lonceng` (daftar jenis, penerima, teruskan), `PeriksaLonceng` (pemeriksaan berkala),
  `Audit::catat()` meneruskan aksi audit penting ke lonceng.
- Jadwal: `php artisan notifikasi:periksa` tiap 5 menit (hanya server lokal / VPS utama, supaya tidak dobel dengan cloud
  pendamping). Butuh layanan Jadwal (`BillingPS-Jadwal` / `schedule:work`) berjalan.
- Dihapus otomatis setelah 90 hari oleh `db:rapikan`.
- Menambah jenis baru: tambahkan ke `Lonceng::JENIS`, lalu panggil `Lonceng::kirim('jenis', judul, isi, subjek: $model)`.
