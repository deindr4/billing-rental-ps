# Analisa Pintar

Menu kasir **Operasional → Analisa Pintar** (`/analisa`), khusus pemilik izin **laporan.laba** (Owner).
Dihitung otomatis dengan aturan tetap dari data sendiri — **tanpa AI, tanpa internet**, jalan di PC rental
maupun cloud. Periode bawaan: bulan ini. Nominal ikut tombol mata (sembunyikan untuk foto).

Kode: `app/Services/Analisa/AnalisaService.php` (ambang batas ada di sana).

## Tab

| Tab | Isi |
|---|---|
| Temuan | Semua temuan diurutkan **Penting → Perhatikan → Info**, tiap temuan dengan saran |
| Audit kasir | Skor risiko 0–100 per kasir + alasannya |
| Keuangan | Omzet & laba vs periode sebelumnya, titik impas harian, proyeksi akhir bulan, rasio, ROI aset, pengeluaran melonjak |
| Operasional | Utilisasi per unit (jam main ÷ jam buka), sesi per jam (jam sepi), omzet per hari, paket terlaris, member aktif/tidur |
| Stok | Barang hilang saat opname, segera habis, margin tipis/rugi, stok mati |

## Skor risiko kasir

| Kondisi (dalam periode) | Poin |
|---|---|
| Transaksi dibatalkan ≥ 3× dan persentasenya ≥ 2× rata-rata kasir (min. 5%) | +25 |
| Kas kurang saat tutup kas ≥ 2 shift, atau total ≥ Rp50.000 | +25 |
| Bonus waktu gratis ≥ 60 menit dan ≥ 2× rata-rata | +15 |
| Bypass TV / kode darurat ≥ 3× | +15 |
| Batal F&B / batal tambah waktu ≥ 5× | +10 |
| Sesi dibatalkan ≥ 3× | +10 |
| Diskon ≥ Rp50.000 dan ≥ 2× rata-rata (min. 10% penjualan) | +10 |
| PIN salah ≥ 3× | +10 |

≥ 50 = merah (Penting), ≥ 25 = kuning (Perhatikan). Skor adalah petunjuk pemeriksaan (cocokkan dengan Log aktivitas &
CCTV), bukan bukti kecurangan.

## Temuan lain

- **Keuangan:** rugi di periode; omzet turun ≥ 20% / naik ≥ 20%; margin F&B < 20%; pengeluaran per kategori
  ≥ 1,5× rata-rata 3 periode sebelumnya (min. Rp100.000); omzet harian di bawah titik impas (periode ≥ 7 hari).
  Titik impas/hari = pengeluaran operasional per hari ÷ margin laba kotor.
- **Operasional:** unit dengan utilisasi < ½ rata-rata; ≥ 2 jam sepi (≤ 20% jam tersibuk, di dalam jam buka
  Pengaturan Operasional); ≥ 5 member tidak datang > 30 hari.
- **Stok:** selisih kurang saat opname (nilai = qty × harga pokok rata-rata, ≥ Rp100.000 = Penting); produk dengan
  harga jual ≤ harga pokok; modal di stok mati (tidak terjual 30 hari) ≥ Rp100.000; produk habis ≤ 3 hari lagi
  (rata-rata penjualan 14 hari).
