# Turnamen

Menu kasir **Turnamen** (izin "Turnamen"). Halaman publik `/turnamen/<slug>` untuk daftar online, bagan & klasemen;
turnamen yang berjalan / dibuka juga tampil di billboard.

## Format

| Format | Cara main | Juara |
|---|---|---|
| **Sistem gugur** | Kalah sekali langsung tersingkir. Peserta ganjil → ada yang dapat *bye* (otomatis lolos). | Pemenang final; juara 3 = yang kalah di semifinal |
| **Gugur ganda** | Baru tersingkir setelah kalah **dua kali**. Bagan atas (belum pernah kalah), bagan bawah (sudah kalah sekali), grand final. Bila juara bagan bawah memenangkan grand final, dimainkan **final ulang** (juara bagan atas baru kalah sekali). | Pemenang grand final / final ulang; juara 3 = yang kalah di final bagan bawah |
| **Liga** | Semua peserta saling bertemu (sekali, atau pulang-pergi). Seri boleh. Menang 3, seri 1, kalah 0. | Klasemen: poin → selisih gol → gol |
| **Fase grup + gugur** | Peserta dibagi ke 2–8 grup (unggulan tersebar), dalam grup saling bertemu. Juara grup (atau juara + runner-up) lolos ke babak gugur yang dipasangkan **silang antar grup** (A1 vs B2, B1 vs A2, …), jadi peserta dari grup berbeda saling bertemu sampai final. | Dari babak gugur |

Bagan / jadwal dibuat saat **Mulai & buat bagan**: hanya peserta **lunas** yang ikut. Nomor unggulan (opsional)
memisahkan peserta kuat. Fase grup selesai → babak gugur dibuat otomatis; hasil grup terkunci setelahnya.

## Bundling F&B

Isi **Bonus F&B gratis** (mis. Teh Botol Kotak, jumlah 1) → setiap peserta yang membayar pendaftaran mendapat produk itu:
tercatat sebagai item Rp0 di transaksi pendaftaran, **stok berkurang**, modalnya (HPP rata-rata) masuk laporan laba.
Pembatalan transaksi mengembalikan stok. Halaman publik & billboard menampilkan "Rp20.000 + Teh Botol Kotak".

## Keuangan & total hadiah

Form turnamen menampilkan hitungan langsung saat diisi; tab **Keuangan** menampilkan dua kolom:

| | Perkiraan (kuota penuh) | Realisasi (peserta lunas) |
|---|---|---|
| Pemasukan | kuota × biaya daftar | lunas × biaya daftar |
| Modal bonus | kuota × jumlah bonus × HPP produk | lunas × jumlah bonus × HPP |
| **Dana bersih** | pemasukan − modal bonus | |
| Total hadiah | diisi admin | |
| **Sisa untuk rental** | dana bersih − total hadiah (merah = rugi) | |

Contoh: daftar Rp20.000 × 16 peserta = Rp320.000; bonus air mineral modal Rp2.500 × 16 = Rp40.000; dana bersih Rp280.000;
total hadiah Rp150.000 → sisa Rp130.000. Saran hadiah = 50 / 60 / 70% dari dana bersih (dibulatkan ribuan).
HPP produk diambil dari stok masuk (menu Stok, isi harga beli); bila belum ada, modal bonus dihitung Rp0.
