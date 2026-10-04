# TV Agent (Android TV / Google TV)

Aplikasi di TV rental: layar kunci, timer, pindah ke HDMI PS, bypass PIN, kode darurat offline.
Kontrak API: [`../docs/tv-agent-api.md`](../docs/tv-agent-api.md).

## Memasang di TV (tanpa kabel / adb)

Sekali per TV, sekitar 5 menit:

1. Pastikan TV dan komputer server di **jaringan yang sama**.
2. Di TV buka **Play Store** → pasang aplikasi **Downloader** (by AFTVnews).
3. Izinkan Downloader memasang aplikasi:
   Settings → Apps → Special app access → **Install unknown apps** → Downloader → **Allow**.
4. Buka Downloader → ketik alamat **`http://<IP-server>/apk`** (contoh `http://192.168.50.9/apk`) → Go → **Install**.
5. Buka **TV Agent** → isi alamat server → muncul **kode 6 angka**.
6. Di komputer kasir: **Admin → Rental → Perangkat TV → Pasangkan TV** → masukkan kode → pilih unit.

Setelah itu update berikutnya datang otomatis dari server (menu **Platform → Rilis APK TV**).

### Izin tambahan (disarankan)

Cek di **Admin → Perangkat TV → Diagnostik** apa yang masih "Tidak":

| Diagnostik | Cara memberi izin |
|---|---|
| Izin tampil di atas (timer) | Di TV Agent: Menu staf → Info & diagnostik → **Izin tampil di atas**. Jika menu tidak ada di TV, lewat adb: `adb shell appops set id.rentalps.tvagent SYSTEM_ALERT_WINDOW allow` |
| Jadi layar utama (Home) | Tekan tombol Home di remote → pilih **TV Agent** → **Always** (tidak semua TV menawarkan pilihan ini) |
| Izin pasang update | Settings → Apps → Special app access → Install unknown apps → TV Agent → Allow |

## Mode kiosk (TV selalu di TV Agent)

Sudah otomatis di aplikasi:
- TV dinyalakan → TV Agent berjalan sendiri (±30 detik setelah boot tanpa langkah di bawah).
  Kebanyakan TV (mis. TCL Google TV) saat dinyalakan dengan remote hanya **bangun dari standby**, bukan boot:
  sejak APK 0.6.7 TV Agent juga menangkap "layar menyala" lalu kembali ke layar kunci / HDMI sesi.
  Syarat: **Izin tampil di atas** sudah diberikan (Android 10+ melarang aplikasi membuka layar dari latar
  belakang tanpa izin itu). Paling andal: jadikan TV Agent **layar utama** (langkah di bawah).
- Tombol **Back** diabaikan. Tombol **Home** saat TV terkunci → muncul **"Masukkan PIN staf untuk keluar"**.
  PIN benar (pengguna dengan izin *Bypass TV*) atau kode darurat → layar utama Google TV terbuka **5 menit**,
  lalu TV otomatis terkunci lagi. Tidak diisi 30 detik → dialog tertutup, TV tetap terkunci.
  PIN diatur di Admin → Pengaturan → Pengguna → **PIN persetujuan**; salah 5x → diblokir 5 menit.
- Aksi staf di Menu staf yang berbahaya (ganti server, izin, input HDMI) wajib **PIN supervisor/owner**
  atau **kode darurat** (jika server tidak bisa dihubungi).

Disarankan (sekali per TV) — **jadikan TV Agent layar utama (Home)**, supaya saat TV menyala yang tampil
pertama langsung TV Agent dan tombol Home tidak pernah membuka layar Google TV:

1. Di TV: Menu staf → Info & diagnostik → **Jadikan layar utama** → pilih TV Agent.
2. Jika TV tidak menampilkan pilihan itu (sebagian Google TV), lewat adb dari komputer:
   ```
   adb shell cmd role add-role-holder android.app.role.HOME id.rentalps.tvagent
   ```
   Kembalikan ke layar bawaan: `adb shell cmd role remove-role-holder android.app.role.HOME id.rentalps.tvagent`

Status "Jadi layar utama" terlihat di Admin → Perangkat TV → Diagnostik.

## Build (developer)

Butuh JDK 17+ & Android SDK (sudah terpasang di PC server, lihat env `JAVA_HOME`, `ANDROID_HOME`).

```bash
cd tv-agent
./gradlew assembleDebug          # hasil: app/build/outputs/apk/debug/app-debug.apk
```

Naikkan `versionCode` & `versionName` di `app/build.gradle.kts` setiap rilis, lalu unggah APK di
**Platform → Rilis APK TV** (login super admin). `versionCode` di form harus sama dengan di APK.

### Emulator

AVD `TV14` (Android TV 14, sama dengan Xiaomi MiTV-MZTU0), data di `E:\.android\avd`:

```bash
ANDROID_AVD_HOME='E:\.android\avd' emulator -avd TV14
adb -e install -r app/build/outputs/apk/debug/app-debug.apk
```

Dari emulator, komputer ini dijangkau lewat `10.0.2.2` (misal server `10.0.2.2:8000`).
