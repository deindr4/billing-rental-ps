# Sewa Playbox (PlayStation bawa pulang)

Penyewaan konsol yang **dibawa pulang** penyewa (rumah / kost) — berbeda dengan Rental PS di tempat.
Inventarisnya terpisah dari unit rental, pembayarannya **di muka**, dan ada jaminan.

## Menu

| Tempat | Isi |
|---|---|
| Kasir → **Sewa Playbox** (`/playbox`) | Daftar sewa berjalan & riwayat (tampilan **kotak** atau **daftar/tabel** 15 per halaman, diingat per login), tombol Sewa baru, Bayar, Kembali, Perpanjang, Surat, WA, Maps, Batal |
| Kasir → Sewa Playbox → **Sewa baru** (`/playbox/sewa`) | Formulir 4 langkah (lihat di bawah) |
| Admin → Sewa Playbox → **Playbox** | Unit (kode, nama, nomor seri), daftar kelengkapan + harga ganti, tarif jam/hari/minggu/bulan, denda telat, saran deposit, status |
| Admin → Sewa Playbox → **Penyewa** | Data penyewa, foto & KTP, lokasi, riwayat sewa, **daftar hitam** |
| Admin → Pengaturan → Operasional → *Sewa Playbox* | Toleransi telat (menit, default 30), pengingat WA (jam sebelum jatuh tempo, default 3, 0 = mati), syarat & ketentuan |

Izin kasir: `playbox.kelola` (bawaan Kasir & Supervisor). Admin Playbox/Penyewa ikut akses panel admin (Owner & Supervisor).

## Sewa baru (4 langkah)

1. **Penyewa** — cari dari nomor HP (penyewa lama langsung terisi). Nama, HP (angka saja; 08… disimpan 628…),
   NIK (16 angka, terenkripsi),
   alamat, rumah/kost, **koordinat**: tempel link Google Maps (termasuk `maps.app.goo.gl`) atau `lat, lng`. Tombol
   "Lokasi saya" hanya muncul di HTTPS. Foto penyewa & foto KTP wajib untuk penyewa baru (kamera tablet), otomatis
   dikompres ke WebP.
2. **Unit & durasi** — pilih unit tersedia, satuan (jam/hari/minggu/bulan, hanya yang bertarif) dan jumlah. Harga
   mingguan/bulanan diisi sendiri sehingga bisa lebih murah dari harian × 7.
3. **Jaminan & kondisi** — identitas asli (KTP/SIM/…, nomor), uang deposit (saran dari unit), barang lain (keterangan
   + foto). Checklist kelengkapan keluar (jumlah & kondisi) + foto kondisi.
4. **Syarat & tanda tangan** — penyewa membaca syarat lalu tanda tangan di layar.

Simpan → transaksi `SWB-…` (jenis *sewa luar*) dibuat dan dialog Pembayaran terbuka. Deposit tunai masuk laci sebagai
**titipan** (bukan omzet). Bila WhatsApp aktif di cabang, ringkasan sewa terkirim ke nomor penyewa.

## Selama disewa

- **Perpanjang** — dari jatuh tempo lama (bukan dari sekarang), membuat tagihan baru; pengingat WA dibuka lagi.
- **Pengingat WA** — sekali, *N* jam sebelum jatuh tempo (jadwal tiap 10 menit, hanya di server lokal agar tidak
  dobel dengan cloud). Tanpa WA aktif: tombol WA di kartu membuka wa.me dengan pesan siap kirim.
- **Surat sewa** — halaman siap cetak / kirim WA: data penyewa (NIK disamarkan), unit, jaminan, kelengkapan, syarat,
  tanda tangan.
- **Batal** — salah input: transaksi dibatalkan, deposit dikembalikan, unit kembali tersedia.

## Pengembalian

- Checklist dibandingkan dengan saat keluar. Jumlah kurang → otomatis *hilang* dengan biaya harga ganti × kurang;
  *rusak* → harga ganti (biaya bisa diubah).
- **Denda telat** di luar toleransi: per jam (bila diisi) atau per hari, bisa diubah kasir.
- Deposit dikembalikan penuh dari laci, lalu tagihan (denda + ganti rugi) dipotong dari deposit bila dicentang;
  kekurangan dibayar dengan metode pilihan. Tanpa deposit, tagihan dibuka di dialog Pembayaran.
- Ada barang tidak *baik* → unit berstatus **servis** (ubah di admin setelah diperbaiki).

## Daftar hitam

Penyewa bermasalah ditandai di Admin → Penyewa (dengan alasan). Kasir biasa tidak bisa menyewakan ke penyewa daftar
hitam; Supervisor/Owner bisa melanjutkan dengan mencentang persetujuan (tercatat di log aktivitas).

## Berkas privat

Foto penyewa, KTP, foto kondisi, barang jaminan & tanda tangan disimpan di `storage/app/private/tenants/{tenant}/…`
(bukan folder publik) dan hanya dibuka lewat `/berkas/…` oleh pengguna tenant yang sama dengan izin `playbox.kelola`.

## Laporan & kas

- Laporan: baris **Sewa Playbox** di pendapatan; denda & ganti rugi ikut transaksi pengembalian.
- Laporan kas/shift: baris "Deposit sewa Playbox (titipan)" (masuk − keluar) agar setoran laci cocok.
- Tabel `playbox`, `penyewa`, `sewa_playbox` ikut sinkron ke cloud.
