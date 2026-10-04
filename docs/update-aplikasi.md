# Update aplikasi lewat GitHub

Kode & rilis ada di repo publik **github.com/deindr4/billing-rental-ps**. Setiap pemasangan (PC rental Windows &
server cloud) memeriksa rilis terbaru di GitHub dan memberi tahu admin — **tidak memasang sendiri**.

## Yang dilihat admin

- **Dasbor → Status sistem**: kartu "Versi aplikasi" (kuning = "Update x tersedia").
- **Pengaturan → Pemeliharaan sistem → Update aplikasi**: versi terpasang & terbaru, catatan fitur, tombol
  **Unduh** file yang cocok (PC Windows: `.exe`, server cloud: `.tar.gz`) dan **Halaman rilis**. Tombol **Cek sekarang**.
- Diperiksa otomatis tiap 6 jam (`php artisan update:cek`, ikut scheduler). Tanpa token (API GitHub publik).
- Matikan: `UPDATE_REPO=` (kosong) di `.env`, lalu Optimalkan.

## Memasang update

| Pemasangan | Cara |
|---|---|
| PC rental Windows | Unduh `BillingPS-Setup-<versi>.exe` → jalankan di PC itu. Otomatis backup → update → layanan menyala lagi (± 2–5 menit). |
| Server cloud | Unduh `BillingPS-cloud-<versi>.tar.gz` → langkah "Update versi baru" di [cloudpanel.md](cloudpanel.md). |
| TV | `tv-agent-<versi>.apk` → Admin → Rilis APK → unggah → Push update. Installer Windows mendaftarkannya otomatis. |

## Menerbitkan versi baru (PC pengembang)

1. Naikkan file `VERSION` (format `YYYY.MM.DD`, rilis kedua di hari yang sama `YYYY.MM.DD.1`) & tulis changelog
   (`docs/changelogbill.txt`, `docs/changelog.txt`) bertanggal sama.
2. Commit & `git push`.
3. Build: `installer\build.ps1` (exe) dan `installer\build.ps1 -PaketCloud -LewatiAset` (tar.gz). Bangun APK dulu bila berubah.
4. Terbitkan: `powershell -ExecutionPolicy Bypass -File installer\rilis.ps1`
   (cek dulu catatannya: tambahkan `-Uji`). Membuat tag `v<versi>`, catatan dari changelog, mengunggah exe, tar.gz & APK.
   Token GitHub diambil dari Git Credential Manager (akun yang login di git).

Versi dibandingkan dengan `version_compare` — `2026.10.05` > `2026.10.04.1` > `2026.10.04`.
