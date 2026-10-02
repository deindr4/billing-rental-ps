# Hak akses: Super Admin, Owner, Supervisor, Kasir, Teknisi

Ringkasnya:

| | **Super Admin** | **Owner** |
|---|---|---|
| Apa itu | Pengelola **platform** (aplikasinya). Bukan role, tapi tanda khusus pada akun | Pemilik **satu rental**. Role tertinggi di dalam rental |
| Terikat rental | Tidak (melayani semua rental) | Ya, hanya rentalnya sendiri |
| Akun bawaan (demo) | `admin@billing.test` | `owner@billing.test` |
| Dibuat lewat | `php artisan superadmin` (terminal) | Wizard instal / Admin → Pengguna |
| Aplikasi kasir (`/`) | ❌ selalu diarahkan ke `/admin` | ✅ |
| Panel admin (`/admin`) | ✅ | ✅ |
| Izin di dalam rental | Semua | Semua (tidak bisa dikurangi) |
| Kelola APK TV (unggah, edit, tarik, rollback) | ✅ | ❌ hanya lihat, unduh & push |
| Server cloud: backup, pemeliharaan, token sinkron | ✅ | ❌ |

Role di dalam rental: **Owner**, **Supervisor**, **Kasir**, **Teknisi**. Owner boleh mengubah izin
role lain (Admin → Pengguna → Role) dan membuat role baru; role Owner sendiri tidak bisa diubah.
Tabel izin di bawah adalah **bawaan** saat rental dibuat.

---

## 1. Izin per role (bawaan)

✅ = boleh · PIN = boleh menyetujui dengan PIN (mis. kasir minta, supervisor ketik PIN) · – = tidak

| Izin | Owner | Supervisor | Kasir | Teknisi |
|---|:-:|:-:|:-:|:-:|
| **Kasir** | | | | |
| Rental: mulai, kelola & selesaikan sesi | ✅ | ✅ | ✅ | – |
| POS: jual & tambah F&B ke tagihan | ✅ | ✅ | ✅ | – |
| Terima pembayaran (termasuk Pembayaran online) | ✅ | ✅ | ✅ | – |
| Buka & tutup kas | ✅ | ✅ | ✅ | – |
| **Transaksi** | | | | |
| Lihat daftar transaksi | ✅ | ✅ | ✅ | – |
| Batalkan transaksi | PIN | PIN | – | – |
| Bonus waktu (kompensasi PS restart/hang, menit diketik) | ✅ langsung | ✅ langsung | minta PIN | – |
| **Member** | | | | |
| Daftar & ubah data member | ✅ | ✅ | ✅ | – |
| Top up saldo member | ✅ | ✅ | ✅ | – |
| Koreksi saldo/poin member | PIN | PIN | – | – |
| **Stok** | | | | |
| Lihat stok | ✅ | ✅ | ✅ | ✅ |
| Catat stok masuk (belanja) | ✅ | ✅ | – | – |
| Stok opname | ✅ | ✅ | – | – |
| **Pengeluaran** | | | | |
| Catat pengeluaran | ✅ | ✅ | ✅ | – |
| Pengeluaran melebihi plafon | PIN | PIN | – | – |
| Batalkan pengeluaran | PIN | PIN | – | – |
| **Laporan** | | | | |
| Lihat laporan | ✅ | ✅ | – | – |
| Lihat HPP, margin & laba | ✅ | – | – | – |
| **Aset & maintenance** | | | | |
| Tiket maintenance unit | ✅ | ✅ | ✅ | ✅ |
| Lihat aset, penyusutan & ROI | ✅ | ✅ | – | ✅ |
| Tambah, ubah & lepas aset | ✅ | – | – | – |
| Catat modal & prive owner | ✅ | – | – | – |
| **Turnamen** | | | | |
| Buat turnamen, peserta & skor | ✅ | ✅ | ✅ | – |
| **Audit** | | | | |
| Lihat log aktivitas | ✅ | – | – | – |
| **TV** | | | | |
| Remote TV (volume, layar, restart) | ✅ | ✅ | – | ✅ |
| Pemberitahuan ke layar TV & running text promo (dicatat di log aktivitas) | ✅ | ✅ | ✅ | – |
| Unlock TV, Tutup aplikasi TV, akses staf di TV (Home → OK → PIN), kode darurat | PIN | PIN | – | PIN |
| **Admin** | | | | |
| Masuk panel admin | ✅ | ✅ | – | – |
| Kelola unit, paket harga, produk, iklan, perangkat TV | ✅ | ✅ | – | – |
| Kelola pengguna & role | ✅ | – | – | – |
| Kelola pengaturan | ✅ | – | – | – |

---

## 2. Menu panel admin

| Menu | Super Admin | Owner | Supervisor | Syarat |
|---|:-:|:-:|:-:|---|
| Dasbor: ringkasan operasional & rekap hari ini | rental dipilih | ✅ | ✅ | rekap: izin "lihat laporan" |
| Dasbor: status sistem (server, database, sync) | ✅ | ✅ | – | Owner / izin "kelola pengaturan" |
| Unit, Tipe konsol, Kategori, Paket harga, Produk | ✅ | ✅ | ✅ | izin "kelola unit, paket & produk" |
| Iklan billboard | ✅ | ✅ | ✅ | idem |
| Perangkat TV (pairing, kode darurat, push update) | ✅ | ✅ | ✅ | idem |
| Rilis APK TV | ✅ kelola penuh | lihat, unduh, push | – | lihat: Owner |
| Pengguna & Role | ✅ | ✅ | – | izin "kelola pengguna & role" |
| Pengaturan: Operasional, Tampilan, Member, Notifikasi | ✅ | ✅ | – | izin "kelola pengaturan" |
| Pengaturan: Pembayaran online (gateway) | ✅ | ✅ | – | khusus Owner / Super Admin |
| Laporan | ✅ | ✅ | ✅ | izin "lihat laporan" |
| Log aktivitas | ✅ | ✅ | – | izin "lihat log aktivitas" |
| Sinkronisasi | ✅ | ✅ di server lokal | – | di cloud: hanya Super Admin (buat token) |
| Backup & Restore | ✅ | ✅ di server lokal | – | di cloud: hanya Super Admin |
| Pemeliharaan sistem | ✅ | ✅ di server lokal | – | di cloud: hanya Super Admin |

**Kenapa beberapa menu dibatasi di server cloud?** Server cloud bisa melayani banyak rental sekaligus.
Backup, pemeliharaan & token sinkron di sana menyangkut semua rental, jadi hanya Super Admin.
Di server lokal rental (satu rental) Owner boleh semuanya kecuali mengelola rilis APK.

---

## 3. Aplikasi kasir (`/`)

| Halaman | Izin yang dibutuhkan | Owner | Supervisor | Kasir | Teknisi |
|---|---|:-:|:-:|:-:|:-:|
| Rental (grid unit), Jadwal & booking, Lounge/Billboard | Rental | ✅ | ✅ | ✅ | – |
| POS | POS | ✅ | ✅ | ✅ | – |
| Buka / tutup kas | Buka & tutup kas | ✅ | ✅ | ✅ | – |
| Pembayaran online (bayar mandiri) | Terima pembayaran | ✅ | ✅ | ✅ | – |
| Transaksi | Lihat transaksi | ✅ | ✅ | ✅ | – |
| Member | Kelola member | ✅ | ✅ | ✅ | – |
| Stok | Lihat stok | ✅ | ✅ | ✅ | ✅ |
| Pengeluaran | Catat pengeluaran | ✅ | ✅ | ✅ | – |
| Laporan | Lihat laporan | ✅ | ✅ | – | – |
| Maintenance | Tiket maintenance | ✅ | ✅ | ✅ | ✅ |
| Aset & modal | Lihat aset | ✅ | ✅ | – | ✅ |
| Turnamen | Turnamen | ✅ | ✅ | ✅ | – |
| Tombol "Admin" | Masuk panel admin | ✅ | ✅ | – | – |
| Panel TV (ikon TV di kartu unit): **Lock** | Rental | ✅ | ✅ | ✅ | – |
| Panel TV: **Unlock** (pilih menit), **Tutup aplikasi**, Kode darurat | Rental + PIN orang berizin Unlock TV | ✅ | ✅ | minta PIN | – |

Lock tidak butuh PIN (mengunci selalu aman). Tutup aplikasi tidak bisa dikirim lewat tombol remote kartu unit — hanya
dari panel TV dengan PIN.

Super Admin tidak memakai aplikasi kasir (tidak terikat rental, tidak punya kas/shift).

---

## 4. Catatan

- **Owner selalu punya semua izin** rental, walau role-nya diubah. Izinnya tidak bisa dikurangi.
- **Ganti password akun bawaan** sebelum dipakai sungguhan:
  `php artisan superadmin admin@billing.test` (Super Admin), dan Owner lewat Admin → Pengguna.
  Admin → Pemeliharaan sistem memberi peringatan kuning selama password bawaan masih dipakai.
- **Lupa password Super Admin:** `php artisan superadmin email@anda.com` di terminal server.
- Izin "(PIN)": kasir mengerjakan, lalu orang yang punya izin itu mengetik PIN-nya di layar kasir
  sebagai persetujuan (tercatat di log aktivitas).
