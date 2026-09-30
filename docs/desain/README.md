# Desain Stitch — acuan UI/UX

Semua tampilan (kasir desktop, kasir HP, admin, TV) mengikuti desain di folder [`stitch/`](stitch/).
Token warna sudah dipasang di [`resources/css/shared/tokens.css`](../../resources/css/shared/tokens.css).

## Bahasa visual

| Unsur | Aturan |
|---|---|
| Latar | Navy gelap `#0a1420`, panel `#0f1c2b` / `#152537`, garis tipis `#1e3144` |
| Aksen | Mint `#4ade80` (bisa diganti di Admin → Tampilan) — tombol utama, angka uang positif, status siap |
| Status unit | Siap mint · Terisi/open bill biru `#38bdf8` · Segera habis kuning `#fbbf24` · Maintenance oranye `#fb923c` · Offline/bahaya merah `#f87171` |
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
| 10 | `10-tv-layar-kunci-ready-to-play.png` | **TV layar kunci / siap main**: nama stasiun + status, slideshow poster/video promo, jam + zona, info stik & Wi-Fi, QR "Scan untuk buka kunci" + tarif | TV Agent — layar kunci dibuat mengikuti ini. Slideshow & QR bayar menyusul (tahap 8/13) |
| 11 | `11-tv-waktu-habis-rincian-tagihan.png` | **TV waktu habis**: bar status HDMI, header stasiun + jam, panel "WAKTU ANDA TELAH HABIS" + timer 00:00:00, rincian tagihan per item, total belum dibayar, QR bayar/perpanjang, paket perpanjangan, panggil kasir, pengumuman kaki | TV Agent — layar habis/menunggu bayar dibuat mengikuti ini. QR & perpanjang mandiri menyusul (tahap 13) |

## Catatan

- Nama brand di desain ("CYBER PS", "Delta"/"Cyber Lounge") hanya contoh — aplikasi memakai nama tenant & logo dari Admin → Tampilan.
- Angka di desain adalah data contoh.
- Fitur di desain yang belum ada di roadmap aplikasi saat ini: relay/smart plug & monitor daya, target omzet shift, aksesori sewa (stik, headset), antrean lounge.
