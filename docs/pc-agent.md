# Rental PC — agen kiosk Windows

Status: **server & admin sudah siap** (2026-10-05). Aplikasi agen di PC Windows **belum dibuat** — dokumen ini
adalah kontrak untuk aplikasinya nanti.

## Gambaran

- Unit PC = unit biasa dengan **tipe konsol berjenis PC** (Admin → Tipe Konsol → Jenis rental: PC). Paket harga
  tetap per tipe konsol, jadi buat paket "Per Jam PC" dsb. untuk tipe itu.
- Kasir punya menu sendiri **Rental PC** (`/pc`), terpisah dari **Rental PS** (`/`). Menu Rental PC muncul bila
  cabang punya unit PC aktif. **Laporan tetap satu**; ringkasan keuangan memisah *Sewa PS* & *Sewa PC*.
- **Member sama** untuk PS & PC: saldo, poin, dan diskon member berlaku di keduanya.
- Pemain **tidak login di PC**. Kasir memulai sesi dari billing (pilih tamu / member, paket) → agen menerima
  `layar = main` → kiosk terbuka. Sesi selesai / waktu habis → kiosk terkunci lagi (+ aksi akhir sesi).

## Akun Windows

| Akun | Dipakai untuk |
|---|---|
| Admin (utama) | Teknisi: pasang game, update Windows, pasang/perbarui agen. Tidak dipakai pemain. |
| **Pemain (kedua, standard user)** | Login otomatis saat PC menyala. Agen jalan sebagai *shell* / startup akun ini. |

Agen dipasang sebagai **service Windows** (jalan sebelum login, tidak bisa ditutup akun pemain) + komponen UI di
sesi akun pemain (layar kunci, timer, kiosk). Service menjaga UI tetap hidup (bila UI di-kill, dijalankan lagi).

## Pairing & API

Sama persis dengan TV Agent (`docs/tv-agent-api.md`), dengan tambahan:

| Endpoint | Tambahan untuk PC |
|---|---|
| `POST /api/tv/pairing` | `jenis: "pc"`, `android_id` = ID mesin (MachineGuid), `versi_android` = versi Windows, `mac` = MAC kartu LAN |
| `POST /api/tv/heartbeat` | `mac` (dirapikan server ke `AA:BB:CC:DD:EE:FF`) |
| `GET /api/tv/status` | `perangkat.jenis = "pc"` dan blok `pc` (di bawah) |
| `POST /api/tv/verifikasi-pin` | Menu staf di PC (PIN pemilik izin `tv.bypass`): buka Task Manager, keluar kiosk sementara, dll. |
| `POST /api/tv/bypass` | Buka PC sementara tanpa sesi (PIN), sama seperti TV |

Admin memasangkan lewat **Admin → Perangkat TV & PC → Pasangkan TV / PC**. Kode dari PC hanya bisa dipasang ke
unit bertipe PC (dan sebaliknya). `GET /api/tv/update` (rilis APK) **tidak** untuk PC.

### Blok `pc` di status

```json
"pc": {
  "akhir_sesi": "tutup_aplikasi",          // kunci | tutup_aplikasi | logoff | restart
  "task_manager_menit": 5,                 // lama izin Task Manager sementara
  "proteksi": {
    "task_manager": true,                  // blok Task Manager kecuali saat diizinkan
    "cmd_regedit": true,                   // blok cmd, PowerShell, regedit
    "pengaturan": true,                    // blok Settings & Control Panel
    "tombol_windows": true,                // blok tombol Windows, Alt+Tab keluar kiosk, Ctrl+Alt+Del
    "usb": false,                          // blok penyimpanan USB
    "unduhan": false                       // hapus Downloads & Desktop pemain saat sesi selesai
  },
  "aplikasi": [ { "nama": "Steam", "path": "C:\\Program Files (x86)\\Steam\\steam.exe" } ]
}
```

Diatur di **Admin → Pengaturan Operasional → Rental PC (agen kiosk Windows)**. `layar`, `sesi`, `running_text`,
`pengumuman`, `server` (failover lokal ↔ cloud) dan `perintah` dipakai sama seperti TV.

### Perintah remote (Reverb / `perintah` di status)

| Perintah | Dari | Yang dilakukan agen |
|---|---|---|
| `kunci` | Kasir (izin Remote) | Kunci layar kiosk |
| `tutup_game` | Kasir (izin rental) | Paksa tutup aplikasi di depan (game hang), kembali ke kiosk; sesi tetap jalan |
| `izin_task_manager` | Kasir (izin Remote) | Izinkan Task Manager selama `data.menit`, lalu blok lagi |
| `logoff_pc` / `restart_pc` / `matikan_pc` | Kasir (izin Remote) | Log off akun pemain / restart / shutdown |
| `pemberitahuan` | Kasir | Tampilkan pesan di tengah layar (`data` sama dengan TV) |
| `bangunkan_pc` | Server | Kirim paket Wake-on-LAN ke `data.mac` (PC lain yang mati di LAN yang sama) |

Perintah TV (volume, layar, HDMI, update APK) ditolak server untuk PC, dan sebaliknya.

### Nyalakan PC (Wake-on-LAN)

Tombol daya di kartu PC yang mati. Server lokal mengirim *magic packet* sendiri bila ekstensi PHP `sockets`
aktif; bila tidak (atau dari server cloud), perintah `bangunkan_pc` dititipkan ke PC lain yang sedang menyala di
cabang itu. PC harus mengaktifkan Wake-on-LAN di BIOS & driver kartu jaringan.

## Task Manager & game crash

Proteksi tetap aktif, tapi ada tiga jalan saat game hang:
1. **Tutup game** dari kartu unit kasir (paling cepat, tanpa Task Manager).
2. **Izinkan Task Manager** dari kartu unit (supervisor/owner) — terbuka N menit, lalu diblok lagi.
3. **Menu staf di PC** (tombol rahasia / kombinasi tombol → PIN) → buka Task Manager.

## Belum dibuat (aplikasi agen)

- Layar kunci, timer melayang, kiosk peluncur aplikasi, menu staf PIN, pemberitahuan & running text.
- Proteksi (kebijakan grup/registry per akun pemain), service penjaga, pemulihan otomatis.
- Installer agen (.exe) & update agen.
