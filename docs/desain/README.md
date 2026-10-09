# Desain Stitch — acuan UI/UX

Semua tampilan (kasir desktop, kasir HP, admin, TV) mengikuti desain di folder [`stitch/`](stitch/).
Token warna sudah dipasang di [`resources/css/shared/tokens.css`](../../resources/css/shared/tokens.css).

## Bahasa visual

| Unsur | Aturan |
|---|---|
| Latar | Navy gelap `#0a1420`, panel `#0f1c2b` / `#152537`, garis tipis `#1e3144` |
| Aksen | Mint `#4ade80` (bisa diganti di Admin → Tampilan) — tombol utama, angka uang positif, status siap |
| Status unit | Siap mint · Terisi/open bill biru `#38bdf8` · Segera habis kuning `#fbbf24` · Maintenance oranye `#fb923c` · Offline/bahaya merah `#f87171` |
| Ikon menu & tombol remote TV | Berwarna per fungsi (permintaan user, tambahan dari Stitch) — token `--ikon-*` / kelas `text-ik-*` (biru, hijau, teal, ungu, indigo, pink, kuning, oranye, merah; versi terang lebih gelap). Warna menu diatur di `App\Support\MenuOperator` (`warna`). Remote TV: daya oranye, volume biru, senyap kuning (aktif merah), bypass ungu (aktif biru), restart teal. Tombol sekunder berwarna: `.btn-tint` + `tint-biru/hijau/kuning/oranye/teal` (latar 12% + garis 40% warna + cahaya tipis; bukan gradien). Makna warna: kuning = waktu/koreksi, oranye = F&B/uang keluar/lock, biru = ubah/konfirmasi/pause, indigo = member/pindah, pink = bonus, teal = cetak/dokumen/tautan, hijau = WhatsApp/uang masuk/unlock, merah = hapus/tutup. Tombol utama `.btn-primary` & `.btn-danger` solid dengan cahaya tipis; tombol netral (kembali/batal/navigasi) tetap `.btn` polos |
| Label | Huruf **kapital kecil, mono, renggang** (`SISA WAKTU RENTAL`, `TOTAL OMSET SHIFT 1`) warna redup |
| Angka | **Mono besar** untuk timer, uang, kode (`00:24:12`, `Rp 1.450.000`) |
| Kartu | Sudut kecil (6–10px), garis tepi tipis, kepala kartu = judul + chip status di kanan |
| Chip | Kotak kecil berlatar gelap: `VIP ROOM 1`, `GOLD`, `TERISI`, `HAMPIR HABIS (3M)` |
| Tombol | Utama mint penuh (teks gelap), sekunder garis tipis, bahaya merah; pintasan keyboard ditulis (`F1`, `F12`) |
| Waktu | Selalu dengan zona cabang (`WIB`/`WITA`/`WIT`) |

## Daftar layar

| # | File | Layar | Status di aplikasi |
|---|---|---|---|
| 01 | `01-desktop-rekonsiliasi-kas-shift.png` | Rekonsiliasi kas shift & audit (omzet, breakdown, pecahan uang, petty cash, okupansi per jam, transaksi terakhir, stok kritis, prosedur tutup kasir) | Sebagian ada (Tutup Kas, Laporan) — belum: pecahan uang, target shift, okupansi per jam |
| 02 | `02-desktop-remote-tv-hardware.png` | Remote TV & Hardware: status TV Agent, matriks relay per unit (arus, tegangan, daya, input HDMI, volume, OSD), billboard lounge, antrean, log insiden | Remote TV **ada** (tahap 8, di kartu unit). Billboard lounge, antrean & panggilan bersuara **ada** (tahap 13: menu Lounge & Billboard). Belum: relay/monitor daya |
| 03 | `03-desktop-rental-station-matrix.png` | Rental Station Matrix: kartu unit (timer, pemain, game, relay), panel kanan rincian tagihan + pembayaran cepat, bar aksi F1–F5 | Grid Rental **ada** — belum: panel kanan tetap, game, member, relay, pintasan F-key |
| 04 | `04-hp-aset-modal-roi.png` | Aset & modal: investasi, nilai buku, penyusutan, ROI, arus modal & prive | **Ada** (tahap 10: menu Aset & Modal + Maintenance) |
| 05 | `05-hp-pengeluaran-petty-cash.png` | Pengeluaran / petty cash: plafon, input cepat (+10k…), kategori, sumber dana, foto nota, riwayat | **Ada** (7.1) — cek kesesuaian tombol nominal cepat |
| 06 | `06-hp-station-detail-pengaturan-unit.png` | Detail stasiun: identitas konsol, metode kontrol (Android TV Agent / relay / VIDAA / manual), IP agent, toleransi bypass, posisi overlay, wallpaper layar kunci, booking, game | Sebagian di Admin → Unit — belum: wallpaper layar kunci, tes koneksi agent |
| 07 | `07-hp-laporan.png` | Laporan: periode, pendapatan, kas operasional, utilisasi, heatmap jam sibuk, komposisi pendapatan, log anomali (void, bypass TV), tren kas, bot Telegram | **Ada** (7.2/7.3; log anomali tahap 11) — belum: tren kas per jam |
| 08 | `08-hp-station-detail-mulai-sesi.png` | Mulai sesi: tamu/member (saldo, poin), paket tetap/open/custom, aksesori (stik, headset), otomatisasi TV (nyalakan via relay, notif 10 menit), ringkasan biaya, prepaid/postpaid | Mulai sesi **ada** + member (tahap 9) — belum: aksesori, relay |
| 09 | `09-hp-pos-fnb-RESOLUSI-KECIL.png` | POS F&B versi HP | **Ada** (tahap 5). Gambar terlalu kecil untuk dijadikan acuan detail |
| 10 | `10-tv-layar-kunci-ready-to-play.png` | **TV layar kunci / siap main**: nama stasiun + status, slideshow poster/video promo, jam + zona, info stik & Wi-Fi, QR "Scan untuk buka kunci" + tarif | TV Agent — layar kunci dibuat mengikuti ini. QR bayar mandiri sudah ada (APK 0.2.0, panel ringkas 0.4.1); nama unit menggantikan "stasiun" (0.4.0). Slideshow belum |
| 11 | `11-tv-waktu-habis-rincian-tagihan.png` | **TV waktu habis**: bar status HDMI, header stasiun + jam, panel "WAKTU ANDA TELAH HABIS" + timer 00:00:00, rincian tagihan per item, total belum dibayar, QR bayar/perpanjang, paket perpanjangan, panggil kasir, pengumuman kaki | TV Agent — layar habis/menunggu bayar dibuat mengikuti ini. QR isi ulang waktu mandiri sudah ada (APK 0.2.0); paket perpanjangan pilihan belum |

## Catatan

- Nama brand di desain ("CYBER PS", "Delta"/"Cyber Lounge") hanya contoh — aplikasi memakai nama tenant & logo dari Admin → Tampilan.
- Angka di desain adalah data contoh.
- Tambahan di luar desain — timer melayang TV (APK 0.6.5): baris info teknis kecil & redup di bawah angka
  (`v0.6.5 · ●L 12ms · ●C 85ms`, titik hijau/kuning/merah), bisa dimatikan di Admin → Pengaturan Operasional.
- Tambahan di luar desain — kartu unit kasir: remote daya dua tombol terpisah, ikon matahari (`bangun`, hijau)
  = Bangunkan TV dan ikon daya (oranye) = Matikan; TV offline/standby menampilkan "TV offline / standby" + Bangunkan.
- Tambahan di luar desain — dialog Pembayaran: bagian lipat "Bayar sekaligus dengan tagihan lain" (daftar centang
  tagihan unit/POS: nama unit, nomor, sisa) di atas bagian member; label total menjadi "Total gabungan N tagihan".
- Tambahan di luar desain — tombol mata (`btn-ghost btn-ikon`, ikon `mata` / `mata-tutup`) di header kasir sebelah
  jam & di topbar admin: sembunyikan nominal untuk foto layar. `<x-rupiah rahasia>` atau semua `<x-rupiah>` di dalam
  `[data-rahasia]` tampil "Rp *******" (CSS di `partials/sembunyi-uang.blade.php`).
- Tambahan di luar desain — Rental PC: menu kasir "Rental PC" (ikon `pc`, teal) di bawah "Rental PS", memakai
  matriks unit yang sama. Kartu unit PC: indikator "PC online/offline", baris remote: Tutup game (`tutup-game`, merah),
  Task Manager (`aktivitas`, kuning) | Pemberitahuan, Bypass | Kunci (`gembok`), Log off, Restart, Matikan;
  PC mati → "PC mati / offline" + tombol daya (Wake-on-LAN). Laporan: baris "Sewa PS" / "Sewa PC".
- Tambahan di luar desain — Analisa Pintar (menu Operasional, ikon `analisa`, ungu): pola halaman Laporan
  (tombol periode, `kartu`, tabel). 3 kartu jumlah temuan (merah/kuning/biru), tab font-mono, daftar temuan memakai
  `kartu kartu-status` dengan garis warna tingkat + chip bagian; seluruh halaman `data-rahasia`.
- Tambahan di luar desain — kaki aplikasi web: `<x-hak-cipta>` teks 11px `text-muted` di tengah, "Copyright © deindr4
  · <teks tambahan rental>"; login: menempel di bawah layar; admin Filament: render hook FOOTER (gaya inline).
  APK TV: di sebelah versi pada bar koneksi kaki layar.
- Sewa aksesori (ada di desain 08 "aksesori (stik, headset)"): pemilih − jumlah + per aksesori (harga · tersedia N)
  di Mulai Rental & panel Kelola Sesi → "Sewa Aksesori" (`btn-tint tint-ungu`, ikon `aksesori`); daftar "Aksesori
  disewa" dengan tombol Kembalikan (teal) / Batal (merah); kartu unit: baris "Aksesori" di bawah Paket.
- Tambahan di luar desain — warna penanda unit: `border-left: 4px` + chip kode (latar 16% warna unit) di kartu unit;
  garis atas tetap warna status. Palet 12 warna (`Unit::PALET`), bisa diganti per unit di admin.
- Tambahan di luar desain — Serah Terima & Laporan Shift: pola halaman Tutup Kas (`max-w-lg`, `surface`, ringkasan
  kas bersama `partials/ringkasan-kas`), langkah bernomor 1–3, daftar "Diteruskan ke kasir berikutnya"; Buka Shift
  menampilkan kartu "Laci sedang dipegang" bila kasir lain bertugas. Menu akun: "Serah Terima (ganti kasir)".
- Tambahan di luar desain — Absen (menu kasir, ikon `jam` hijau): grid kartu karyawan (foto bundar / inisial, status
  "Masuk HH:MM"), lalu panel PIN + input foto kamera + pratinjau, tombol Absen masuk (primary) / pulang (tint oranye).
  Admin: Jam shift, Jadwal mingguan (tabel select per hari), Absensi (foto bundar masuk/pulang).
- Tambahan di luar desain — Rekap gaji (admin): tabel rincian komponen di section Filament, checkbox usulan potongan,
  repeater penyesuaian, aksi header Slip / Setujui / Bayar / Batalkan; slip gaji HTML polos siap cetak (A5/A4).
- Tambahan di luar desain — Stok → Riwayat (Owner): kolom "Harga pokok" + tombol kecil `btn-tint tint-kuning`
  "Koreksi" yang membuka baris isian harga + alasan di bawah baris mutasi.
- Fitur di desain yang belum ada di roadmap aplikasi saat ini: relay/smart plug & monitor daya, target omzet shift, aksesori sewa (stik, headset), antrean lounge.
