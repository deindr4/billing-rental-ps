# Backup otomatis ke Google Drive

Backup data rental otomatis tersimpan juga di **Google Drive pemilik**, supaya aman walau PC rusak, hilang, atau
terkena virus. Di PC dan di Drive masing-masing disimpan **4 backup terbaru**; yang lebih lama terhapus sendiri.

---

## Untuk pemilik rental — cukup sekali, ± 2 menit

1. Buka **Admin → Pengaturan → Backup & Restore**, cari bagian **Google Drive**.
2. Klik **Hubungkan Google Drive** → muncul **kode** besar → klik **Buka google.com/device** (kode otomatis tersalin; ada juga tombol **Salin kode**).
3. Di halaman Google yang terbuka: **tempel kode** → **Berikutnya** → pilih akun **Gmail pemilik** → **Izinkan**.

Selesai. Halaman Backup berubah sendiri menjadi **"✅ Terhubung ke …@gmail.com"**, folder
**Delta Billing HuB Backup – (nama rental)** otomatis dibuat di *Drive Saya*, dan setiap backup ikut terkirim.

> Membuka admin dari HP? Buka **google.com/device** di HP, ketik kode yang tampil, lalu **Izinkan**.

**Aman?** Aplikasi hanya bisa melihat folder backup buatannya sendiri — **tidak bisa** membuka foto, dokumen, atau
file lain di Google Drive Anda. Izin bisa dicabut kapan saja dengan tombol **Putuskan**.

### Mengecek

Bagian Google Drive menampilkan **"Terakhir terkirim …"**, dan di **drive.google.com** ada folder backup berisi file
`backup-….zip`.

### Tombol-tombol

| Tombol | Fungsi |
|---|---|
| **Kirim backup terbaru sekarang** | Mengirim saat itu juga (± 1 menit) |
| **Lihat isi Drive** | Melihat backup yang tersimpan di Drive |
| **Folder baru** | Pindah ke folder baru (folder lama tetap ada) |
| **Putuskan** | Berhenti mengirim ke Drive. File di Drive tidak dihapus |

### Bila PC rusak / ganti PC

1. Buka **drive.google.com** → folder backup → klik kanan file **terbaru** → **Download**.
2. Di PC baru jalankan installer → pilih **Pulihkan dari file backup** → pilih file tadi. Semua data kembali.
3. Hubungkan Google Drive lagi (langkah 1–3 di atas).

### Bila ada masalah

| Yang terlihat | Yang dilakukan |
|---|---|
| "Backup ke Google Drive belum aktif di pemasangan ini" | Perbarui aplikasi ke versi terbaru, atau hubungi pengembang (menu **Lisensi MIT** → Telegram) |
| "Kode kedaluwarsa" | Klik **Hubungkan Google Drive** lagi (kode berlaku ± 30 menit) |
| "Izin ditolak" | Ulangi, lalu pilih **Izinkan** |
| "Akses dicabut … hubungkan ulang" | Klik **Hubungkan Google Drive** lagi |
| "Drive penuh" / *storage quota* | Kosongkan Drive & Sampah, atau kurangi **Jumlah backup yang disimpan** |
| Lama "Belum ada yang terkirim" | Buka **Delta Billing HuB Monitor** di Desktop → layanan **Jadwal** harus *Berjalan* |
| Lonceng: "Backup ke Google Drive gagal" | Dicoba ulang otomatis tiap 15 menit; baca pesannya di halaman Backup |

**Pertanyaan umum**
- *Butuh berapa ruang?* Satu backup biasanya beberapa MB – puluhan MB; 4 backup jauh di bawah kuota gratis 15 GB.
- *Ingin menyimpan lebih/kurang dari 4?* Backup & Restore → Pengaturan → **Jumlah backup yang disimpan** (1–30).
- *Beberapa cabang / PC?* Hubungkan masing-masing; satu akun Gmail boleh dipakai semua.
- *Internet putus?* Backup tetap dibuat di PC dan terkirim sendiri setelah internet kembali.
- *Saran:* aktifkan **verifikasi 2 langkah** di akun Gmail pemilik.

---

