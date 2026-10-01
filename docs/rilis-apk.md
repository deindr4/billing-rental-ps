# Rilis, update & rollback APK TV Agent

## Merilis versi baru

1. Naikkan `versionCode` (+1) dan `versionName` di `tv-agent/app/build.gradle.kts`, lalu build.
2. Admin (super admin) → **Platform → Rilis APK TV → Unggah rilis baru**: file APK, versi & kode yang sama persis.
3. Commit lalu beri tag git: `git tag apk-<versi>` (dipakai untuk rollback).
4. TV otomatis diberi tahu. Atau tekan **Push update** (Rilis APK / Perangkat TV) — per TV, TV terpilih, atau semua.
   - Bawaan: TV yang sedang dipakai memasang setelah sesinya selesai.
   - "Pasang sekarang juga": langsung, menutupi game (untuk perbaikan mendesak).
5. Di TV muncul layar pemasang Android → tekan **Instal** dengan remote (aturan keamanan Android).
   Versi terpasang tampil di pojok bawah layar TV dan di kolom **Versi app** (Perangkat TV).

TV versi ≤ 0.2.0 belum mengenal perintah push; lompatan pertama lewat pemberitahuan rilis atau unduh manual `http://IP-server/apk`.

## Rollback (versi baru bermasalah)

Android menolak memasang versionCode yang lebih kecil (downgrade) kecuali aplikasi sistem, dan uninstall
menghapus pairing TV. Jadi rollback = **kode versi lama di-build ulang dengan nomor versi baru**:

1. Rilis APK TV → **Tarik** rilis yang bermasalah (berhenti ditawarkan).
2. Tombol **Rollback ke versi ini** pada versi lama menampilkan perintah lengkap, misalnya:
   ```
   cd tv-agent
   powershell -ExecutionPolicy Bypass -File build-rollback.ps1 -Dari 0.3.1 -Kode 6 -Nama 0.4.1
   ```
   Skrip mengambil kode dari tag `apk-0.3.1`, mengganti nomor versi, build, dan menaruh hasilnya di `tv-agent\rilis\`.
3. Unggah hasilnya sebagai rilis baru (centang **Update wajib**), lalu **Push update** → pasang sekarang juga.

APK harus ditandatangani kunci yang sama (build di PC yang sama / kunci rilis yang sama), kalau tidak Android menolak.
