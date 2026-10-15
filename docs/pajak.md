# Laporan Detail & Pajak

Menu admin untuk bahan pelaporan pajak usaha rental PlayStation & PC. Hanya untuk **Owner** dan role yang diberi izin
`laporan.pajak` (bawaan: Supervisor). Pengaturan Pajak butuh `laporan.pajak` + `admin.pengaturan` (bawaan: Owner).

> Laporan ini **bahan bantu**, bukan nasihat pajak. Tarif, ambang batas & tata cara pajak daerah mengikuti Perda
> kabupaten/kota masing-masing cabang. Konfirmasi ke Bapenda setempat dan AR / konsultan pajak Anda.

## Menu di panel admin

| Menu | Isi |
|---|---|
| **Laporan → Ringkasan** | Laporan yang sudah ada (omzet, laba, metode bayar, perbandingan cabang) |
| **Laporan → Laporan Detail** | Satu baris per transaksi: waktu, nomor, cabang, jenis, unit, pelanggan, kasir, Sewa PS, Sewa PC, F&B, aksesori, Playbox, turnamen, potongan, total, metode bayar. Klik baris untuk melihat item. Filter status (lunas / dibatalkan), jenis, metode bayar, cari nomor / pelanggan. Ekspor **CSV per transaksi** atau **CSV per item** (dibuka di Excel). |
| **Pajak → Laporan Pajak** | Per masa pajak (bulan): ringkasan & potongan, PBJT per cabang (DPP, tarif, pajak terutang, rekap harian lampiran SPTPD), PPh Final UMKM setahun (kumulatif, batas bebas, batas setor). Unduh **PDF** & **CSV rekap harian**. Bawaan: bulan lalu. |
| **Pajak → Pengaturan Pajak** | Nama WP, NPWP/NIK, KBLI, jenis PPh, tarif PPh, omzet di luar aplikasi; per cabang: kabupaten/kota, NPWPD, harga termasuk pajak, tarif PBJT hiburan, PBJT makanan-minuman + ambang. Berisi juga panduan kategori pajak. |

## Analisa: pendapatan rental masuk pajak apa?

| Pendapatan di aplikasi | Pajak | Dasar & tarif umum | Disetor |
|---|---|---|---|
| Sewa PS & PC **main di tempat** (paket / per jam / open billing), tambah waktu, sewa stik/headset, pendaftaran turnamen | **PBJT atas Jasa Kesenian & Hiburan** — kelompok permainan ketangkasan / sarana rekreasi (UU 1/2022 HKPD) | Uang diterima setelah potongan. Tarif paling tinggi 10%; 40–75% hanya untuk diskotek, karaoke, kelab malam, bar & mandi uap/spa | Bapenda kab/kota, SPTPD bulanan |
| Makanan & minuman (F&B) | **PBJT atas Makanan & Minuman** (dulu pajak restoran / PB1) | 10%; tiap daerah punya **ambang omzet** — di bawahnya tidak terutang | Bapenda kab/kota |
| Sewa **Playbox bawa pulang** + ganti rugi | Sewa barang — **bukan objek PBJT hiburan** (tidak dimainkan di tempat usaha) | Hanya masuk omzet PPh | — |
| Top up saldo member | **Bukan omzet** (uang titipan). Pajak & omzet diakui saat saldo dipakai | — | — |
| Seluruh omzet semua cabang | **PPh Final UMKM** (PP 55/2022 jo. PP 20/2026), selama omzet setahun ≤ Rp4,8 miliar | 0,5% omzet bruto per bulan. **Orang pribadi: omzet s.d. Rp500 juta/tahun bebas**; badan dari rupiah pertama | Kas negara, kode billing **411128-420**, paling lambat **tgl 15** bulan berikutnya |

### "Pajak jasa"

- Jasa kesenian & hiburan dan makanan-minuman di tempat **tidak dikenai PPN** karena sudah menjadi objek pajak
  daerah. Jadi "pajak jasa" untuk rental PS/PC = **PBJT** (Pajak Barang dan Jasa Tertentu) di Bapenda.
- **PPh Pasal 23** (2%) hanya muncul bila penyewa berbentuk badan (mis. kantor menyewa rental untuk acara) memotong
  pembayaran Anda. Dengan **Surat Keterangan PP 55** dari DJP pemotongan itu tidak dilakukan; bila tetap dipotong,
  simpan bukti potongnya untuk pembukuan.

### Jenis usaha (KBLI di NIB / OSS)

| KBLI | Kegiatan |
|---|---|
| **93299** | Kegiatan hiburan & rekreasi lainnya YTDL — game center / rental PS & PC main di tempat |
| **77299** | Sewa barang keperluan pribadi & rumah tangga lainnya — sewa PS bawa pulang (Playbox) |

Rental yang punya keduanya biasanya mencantumkan kedua KBLI. KBLI 93292 (taman bertema) tidak cocok.

## Cara aplikasi menghitung

1. **Hanya transaksi lunas** (tanggal dibayar), bukan latihan, bukan top up. Transaksi dibatalkan tidak dihitung.
2. **Kategori per item**: sewa / tambah waktu (unit PS → Sewa PS, unit PC → Sewa PC), aksesori, F&B (produk),
   Playbox (item sewa luar + semua item di transaksi Sewa Playbox, termasuk ganti rugi), lainnya (turnamen) → hiburan.
3. **Potongan** (diskon member, voucher, manual) satu transaksi dibagi ke itemnya **sebanding nilainya**; sisa
   pembulatan masuk item terbesar. Contoh: sewa Rp50.000 + F&B Rp20.000 diskon Rp7.000 → potongan sewa Rp5.000,
   F&B Rp2.000.
4. **PBJT** dari uang diterima (setelah potongan) per masa:
   - Harga **sudah termasuk** pajak (bawaan): pajak = bayar × tarif ÷ (100 + tarif); DPP = bayar − pajak.
     Rp110.000 dengan tarif 10% → DPP Rp100.000, pajak Rp10.000.
   - Harga **belum termasuk** (ditanggung rental): DPP = bayar, pajak = bayar × tarif.
   - F&B hanya dihitung bila diaktifkan, dan tidak terutang bila omzet F&B sebulan di bawah ambang.
5. **PPh Final** dihitung dari omzet setelah potongan **gabungan semua cabang** (satu NPWP), kumulatif Januari–Desember:
   - Orang pribadi: bagian omzet kumulatif di atas batas bebas (Rp500 juta) × 0,5%. Isi "Omzet tahun itu sebelum
     memakai aplikasi" bila aplikasi baru dipakai di tengah tahun.
   - Badan: seluruh omzet × 0,5%.
   - Bila harga sudah termasuk PBJT, omzet ini masih memuat PBJT — pastikan perlakuannya ke AR / konsultan.
   - Peringatan muncul bila omzet setahun melewati Rp4,8 miliar.
6. **Ringkasan & potongan**: omzet kotor − potongan harga = omzet setelah potongan − PBJT − PPh Final = pendapatan
   setelah pajak.

## Langkah pelaporan bulanan

1. Awal bulan, buka **Pajak → Laporan Pajak** (otomatis bulan lalu), pilih cabang.
2. Cek NPWPD & tarif sudah benar (Pengaturan Pajak).
3. Isi **SPTPD PBJT** di Bapenda (online / loket) memakai DPP & pajak terutang; lampirkan **CSV rekap harian** bila
   diminta. Setor sesuai batas waktu Perda.
4. Buat **kode billing PPh Final** (411128-420) di DJP Online / e-Billing sebesar PPh bulan itu, setor paling lambat
   tanggal 15. Tidak ada PPh bila omzet kumulatif masih di bawah Rp500 juta (orang pribadi).
5. Simpan **PDF Laporan Pajak** dan **CSV Laporan Detail** sebagai arsip pembukuan.

## Sumber

- [Klikpajak — Jenis pajak hiburan](https://klikpajak.id/blog/jenis-pajak-hiburan/)
- [DDTC — Apa itu PBJT atas jasa kesenian dan hiburan di UU HKPD](https://news.ddtc.co.id/literasi/kamus/1802275/apa-itu-pbjt-atas-jasa-kesenian-dan-hiburan-di-uu-hkpd)
- [Bapenda Jakarta — PBJT atas jasa kesenian dan hiburan](https://bapenda.jakarta.go.id/artikel/apa-itu-pajak-barang-dan-jasa-tertentu-atas-jasa-kesenian-dan-hiburan)
- [RNOW Consulting — Tarif terbaru pajak daerah Badung mulai 2024](https://rnowconsulting.com/id/content/5548/tarif-terbaru-pajak-daerah-badung-mulai-2024)
- [Goers — Pajak hiburan di Indonesia](https://goersapp.com/blog/pajak-hiburan-indonesia/)
- [Kompas — Pemkot Denpasar tetapkan tarif pajak hiburan](https://money.kompas.com/read/2024/01/24/220955326/pemkot-denpasar-tetapkan-tarif-pajak-hiburan-15-persen)
- [DDTC — Pemda berwenang atur threshold pajak makanan dan minuman](https://news.ddtc.co.id/berita/nasional/1795791/pemda-punya-wewenang-atur-threshold-pajak-atas-makanan-dan-minuman)
- [Pajakku — Ambang batas pajak restoran DKI](https://www.pajakku.com/artikel/ambang-batas-pajak-restoran-dki-naik-jadi-rp42-juta)
- [Adminkita — Izin usaha rental PS](https://adminkita.com/blog/punya-usaha-rental-ps-ini-izin-usaha-yang-harus-kamu-miliki/)
- [Prolegal — Izin bisnis rental PS](https://prolegal.id/bisnis-rental-ps-ini-izin-usaha-yang-harus-dipenuhi/)
- [Bali Zero — KBLI 93299](https://balizero.com/kbli/93299)
- [Hukumonline — PP 20/2026, tarif PPh Final 0,5% UMKM](https://www.hukumonline.com/berita/a/pp-20-2026-beri-masa-transisi--umkm-tetap-bisa-nikmati-tarif-pph-final-0-5-persen-lt6a2b8964462db/)
- [DJP — PPh Final UMKM](https://pajak.go.id/en/node/119953)
- [UKM Indonesia — Panduan pajak UMKM](https://ukmindonesia.id/baca-deskripsi-posts/panduan-lengkap-pajak-umkm-jenis-tarif-dan-cara-bayar)
- [DDTC — Kode billing PPh Final UMKM 411128-420](https://news.ddtc.co.id/berita/nasional/1811296/kode-billing-pph-final-umkm-pakai-411128-420-tak-perlu-npwp-lawan)
