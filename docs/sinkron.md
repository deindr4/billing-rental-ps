# Sinkronisasi server lokal ↔ cloud

Rental punya **server lokal** (PC kasir di LAN, dipakai sehari-hari) dan **server cloud** (VPS).
Server lokal mengirim perubahannya ke cloud dan mengambil perubahan dari cloud **setiap menit**.
Cloud dipakai untuk: TV pindah otomatis saat server lokal mati, owner memantau dari luar, fitur publik (booking, billboard).

## Cara kerja

1. Setiap tabel data punya **trigger database** yang mencatat perubahan ke `sync_antrean`
   (termasuk perubahan lewat query langsung).
2. `php artisan sync` (dijadwalkan tiap menit di server lokal):
   - **dorong**: kirim isi antrean ke `POST /api/sync/dorong` di cloud, lalu hapus dari antrean;
   - **tarik**: ambil `GET /api/sync/tarik?setelah=<kursor>` — perubahan yang dibuat di cloud (atau server lokal lain).
3. Konflik (baris sama diubah di dua server): **`updated_at` terbaru menang**.
4. **Stok produk** dan **saldo/poin/stamp member** dihitung ulang dari buku besar (`stok_mutasi`, `member_mutasi`),
   jadi penjualan / top up di dua server saat koneksi putus tetap terjumlah semua.
5. Role & izin dicocokkan lewat **nama** (ID-nya berbeda di tiap server).
6. Nomor transaksi berbeda per server (`BIL-DGH1-L-...` lokal, `...-V-...` cloud), jadi tidak bentrok.
7. `audit_log` & `notifikasi` (lonceng) **hanya ditambah** (INSERT IGNORE). Notifikasi yang sama dari dua server
   (kolom `kunci` sama) hanya tersimpan sekali — owner yang membuka panel cloud melihat lonceng yang sama.

Tidak disinkronkan: sesi login, cache, antrean tugas, nomor urut, pairing TV, rilis APK, super admin platform,
status baca lonceng (`notifikasi_baca`, `notifikasi_pengguna` — per server).

## Memasang

**Di server cloud (VPS)** — sekali:

```bash
# .env: APP_MODE=cloud
php artisan migrate --force
php artisan db:seed --class=HakAksesSeeder --force   # daftar izin (tanpa data demo)
php artisan superadmin                                 # buat akun super admin (ditanya email & password)
```

Lupa password super admin: jalankan `php artisan superadmin email@anda.com` lagi → password diganti.

Login super admin → **Pengaturan → Sinkronisasi** → isi nama rental → **Buat token** → salin token
(hanya tampil sekali). Pilih tenant hanya jika tenant rental itu sudah ada di cloud; jika belum, biarkan
"Baru (dari server lokal)" — tenant akan terikat saat sinkron pertama. Token tidak bisa dipakai untuk
mengambil alih tenant lain yang sudah ada.

Lewat terminal: `php artisan sync token "Nama rental"`.

**Cara lain (token dibuat di server lokal):** di server lokal klik **Buat token acak** → **Salin**. Di cloud isi nama
server + tempel token di kolom *Token dari server lokal* → **Daftarkan**. Kembali ke lokal → **Simpan** → **Tes koneksi**.
Token baru di server lokal belum dipakai sampai tombol Simpan ditekan.

**Di server lokal** — sekali:

1. Admin → **Pengaturan → Sinkronisasi** → isi alamat cloud (`https://...`) + token → centang aktif → **Simpan** → **Tes koneksi**.
2. Klik **Kirim ulang semua** (memasukkan seluruh data ke antrean), lalu **Sync sekarang**.
3. Pastikan scheduler berjalan. PC hasil installer Windows: otomatis (layanan `BillingPS-Jadwal`).
   Server pengembangan: `php artisan schedule:work`.
4. Supaya TV bisa pindah ke cloud saat PC mati: Admin → **Pengaturan Operasional → Server lokal & cloud** →
   isi alamat server lokal (IP LAN) & alamat cloud (`https://...`).

## Perintah

| Perintah | Keterangan |
|---|---|
| `php artisan sync` | dorong + tarik sekarang |
| `php artisan sync kirim-ulang` | masukkan semua data ke antrean |
| `php artisan sync tarik-ulang` | ambil ulang semua perubahan dari cloud (otomatis setelah restore backup) |
| `php artisan sync pasang-trigger` | pasang ulang trigger (**wajib setelah menambah tabel baru** ke `App\Support\Sinkron\DaftarTabel`) |
| `php artisan sync token "Nama"` | (cloud) buat token server lokal |
| `php artisan sync bersihkan` | (cloud) hapus antrean > 30 hari (terjadwal harian) |

Server lokal yang mati lebih dari 30 hari: setelah hidup, jalankan **Kirim ulang semua** dan **Ambil ulang semua**.

## Menguji di satu PC

```bash
# .env.cloud = salinan .env dengan APP_MODE=cloud, DB_DATABASE=billing_ps_cloud, APP_URL=http://127.0.0.1:8001
APP_ENV=cloud php artisan migrate --force
APP_ENV=cloud php artisan db:seed --class=HakAksesSeeder --force
APP_ENV=cloud php artisan sync token "Uji"
APP_ENV=cloud php artisan serve --port=8001
```

Lalu di server lokal isi alamat `http://127.0.0.1:8001` + token tersebut.
