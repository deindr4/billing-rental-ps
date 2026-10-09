# Karyawan, shift & serah terima

Rancangan (keputusan pemilik, 2026-10-09). Dikerjakan bertahap:

| Tahap | Isi | Status |
|---|---|---|
| A | Satu laci per cabang + serah terima shift (modal tetap, setoran) | ✅ |
| B | Data karyawan (profil, boleh tanpa akun login) | ✅ |
| C | Jadwal shift & absensi (PIN + foto selfie) | ✅ |
| D | Gaji & bonus (pokok bulanan / per shift / per jam + bonus target omzet, potongan selisih kas disetujui owner) | ⬜ |

## A. Satu laci & serah terima

**Aturan laci**
- Satu cabang = satu laci = **satu shift terbuka**. Kasir kedua tidak bisa membuka shift selama laci dipegang orang lain.
- Pemegang laci = penanggung jawab kas (`shifts.user_id`).
- **Owner & Supervisor** (izin `shift.bantu`) boleh ikut bertransaksi di laci yang sedang dipegang kasir; uangnya
  masuk ke shift itu. Kasir lain harus menerima laci lewat serah terima.
- Uang masuk ke **shift yang menerima uang**: sesi dimulai di shift A tapi dibayar di shift B → kas B.

**Serah terima (ganti kasir)** — menu akun → *Serah Terima (ganti kasir)* (`/shift/serah-terima`)
1. Kasir lama menghitung laci (kas fisik). Selisih dengan kas seharusnya wajib diberi keterangan.
2. Isi **uang yang ditinggal** untuk kasir berikutnya — bawaan = *modal tetap* cabang
   (Admin → Pengaturan Operasional → Kas & pengeluaran → Modal tetap di laci). Sisanya = **setoran** ke owner/brankas.
3. Pilih kasir penerima; **penerima mengetik PIN-nya** di perangkat yang sama.
   Penerima boleh menghitung ulang: bila beda dengan yang ditinggal → **selisih serah terima** (wajib keterangan,
   log aktivitas "Selisih serah terima", anomali).
4. Shift lama ditutup & shift penerima langsung dibuka (kas awal = uang yang diterima).
5. Halaman **laporan shift** (`/shift/{id}/laporan`, bisa dicetak): ringkasan kas & non-tunai, kas fisik, selisih,
   ditinggal, setoran, penerima, serta **sesi masih main & tagihan belum dibayar** yang diteruskan. Kasir lama
   ditawari *Keluar & ganti kasir*. Laporan juga dikirim ke Telegram/WA bila diaktifkan (sama seperti tutup kas).

Kasir lama sudah pulang? Owner/Supervisor bisa melakukan serah terima atas namanya.

**Tutup kas (akhir hari)** — tanpa penerus: hitung laci, isi uang yang ditinggal untuk besok; sisanya setoran.
Besok, Buka Shift menyarankan kas awal = uang yang ditinggal.

## B. Data karyawan

Admin → **Karyawan → Karyawan** (izin `karyawan.kelola`, bawaan hanya Owner).
- Profil: nama, jabatan, telepon, cabang utama, foto (dikompres WebP), aktif bekerja.
- **Akun login** opsional: kasir/supervisor ditautkan ke penggunanya; OB/cleaning boleh tanpa akun.
  Tombol **Ambil dari pengguna** membuat data karyawan untuk semua akun login yang belum punya.
- Data pribadi (NIK, tanggal lahir, alamat, kontak darurat) & rekening (bank, nomor, atas nama).
  **NIK & nomor rekening terenkripsi** di database dan tidak ditulis ke log aktivitas.
- Kepegawaian: tanggal masuk / keluar (masa kerja dihitung otomatis). Karyawan tidak dihapus — nonaktifkan.
- **Gaji & bonus** (dipakai rekap gaji tahap D): periode (bulanan / mingguan / harian), gaji pokok per bulan,
  upah per shift/hari hadir, upah per jam, target omzet per shift + bonus (nominal per shift tercapai, atau persen
  dari kelebihan target).
- **PIN absen**: karyawan tanpa akun login punya PIN sendiri; yang punya akun memakai PIN akunnya.

Tabel `karyawan` ikut sinkron lokal ↔ cloud.

## C. Jadwal & absensi

**Admin → Karyawan**
- **Jam shift**: nama, jam mulai–selesai (selesai < mulai = lewat tengah malam, mis. 17:00–02:00), toleransi terlambat.
- **Jadwal mingguan**: tabel karyawan × hari (Senin–Minggu), tiap sel pilih jam shift atau *Libur*;
  tombol "= Senin" menyamakan semua hari. Berulang tiap minggu.
- **Absensi**: riwayat dengan foto masuk & pulang, jadwal, terlambat, lama kerja; filter karyawan, periode,
  "hanya yang terlambat". **Koreksi** (mis. lupa absen pulang) wajib alasan, dihitung ulang, tercatat di log aktivitas.
  Absensi tidak bisa ditambah dari admin — hanya dari aplikasi kasir (PIN + foto).

**Aplikasi kasir → Absen** (`/absen`)
1. Pilih nama (karyawan aktif cabang ini / tanpa cabang utama).
2. Ketik PIN (akun login, atau PIN absen karyawan). Salah 5× → diblokir 5 menit.
3. Ambil foto selfie — memakai kamera bawaan tablet (`<input capture>`), karena tablet membuka aplikasi lewat
   `http://` LAN dan akses kamera langsung dari browser hanya diizinkan di HTTPS. Foto dikompres WebP (±30–60 KB).
4. Masuk: **terlambat** = melewati jam mulai + toleransi (dihitung dari jam mulai). Masuk tanpa jadwal hari itu
   tetap dicatat (lembur / tukar shift). Pulang: **lama kerja** & **pulang cepat** (sebelum jam selesai).

Kasir yang membuka shift & belum absen langsung diarahkan ke halaman Absen. Laporan serah terima menampilkan
tombol *Absen pulang* (penyerah) & *Absen masuk* (penerima).

Catatan: foto absen tersimpan di PC tempat absen; sinkron cloud hanya membawa data (foto tidak ikut).

Kolom baru `shifts`: `kas_ditinggal`, `setoran`, `diserahkan_ke`, `shift_sebelum_id`, `shift_berikut_id`,
`selisih_terima`, `serah_terima` (JSON potret). Pengaturan: `kas.modal_tetap` (per cabang, bawaan Rp200.000).
