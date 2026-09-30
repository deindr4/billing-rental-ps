# API TV Agent

Kontrak antara server billing dan aplikasi TV Agent (Android TV / Google TV).
Semua endpoint di bawah `/api/tv`, request & response JSON.

## 1. Pairing (TV belum terdaftar)

```
POST /api/tv/pairing
{ "android_id": "...", "merek": "Xiaomi", "model": "TV A 43", "versi_android": "11", "versi_app": "0.1.0" }

201 { "kode": "482913", "kunci": "<48 karakter>", "kedaluwarsa_detik": 600, "interval_cek_detik": 3 }
```

TV menampilkan `kode` besar di layar, simpan `kunci` di memori, lalu cek tiap 3 detik:

```
POST /api/tv/pairing/cek   { "kunci": "..." }

200 { "status": "menunggu", "kedaluwarsa_detik": 540 }
200 { "status": "berhasil", "token": "...", "rahasia_offline": "...", "perangkat_id": "..." }   ← hanya sekali
410 { "status": "kedaluwarsa" }      → minta kode baru
404 { "status": "tidak_dikenal" }    → minta kode baru
```

Simpan `token` & `rahasia_offline` di penyimpanan terenkripsi (EncryptedSharedPreferences).
Admin memasangkan lewat panel: **Rental → Perangkat TV → Pasangkan TV**.

## 2. Endpoint TV terdaftar

Header: `Authorization: Bearer <token>`, `Accept: application/json`.
Jawaban `401 { "kode": "perlu_pairing" }` → hapus token, kembali ke layar pairing.

| Endpoint | Keterangan |
|---|---|
| `GET /status` | Status lengkap (lihat bawah). Juga mencatat TV online. |
| `POST /heartbeat` | `{ versi_app, versi_android, layar }` — kirim tiap 30 detik & saat tampilan berubah |
| `POST /bypass` | `{ pin }` → buka TV tanpa sesi selama `durasi_bypass_menit`. 422 jika PIN salah/tanpa izin |
| `POST /bypass/akhiri` | Tutup bypass lebih awal |
| `POST /broadcasting/auth` | Otorisasi channel Reverb (lihat bagian 3) |
| `POST /panggil-kasir` | Pelanggan menekan "Panggil Kasir" → notifikasi berbunyi di aplikasi kasir (maks. 1x / 30 detik per TV) |

Field tambahan di `GET /status` untuk layar desain Stitch (lihat `docs/desain/`):
`unit.konsol`, `unit.kategori`, `unit.lokasi`, `unit.tarif_per_jam`, `tema.zona_waktu` + `tema.zona_label` (jam di TV
memakai zona cabang), `pengumuman` (teks berjalan), `pengaturan.suara_aktif`, dan rincian tagihan
`sesi.tagihan.items[] {nama, jenis, qty, harga_satuan, subtotal, keterangan}`, `diskon[]`, `subtotal`, `total_diskon`, `sisa`.

### `GET /status`

```json
{
  "server_time_ms": 1759132800000,
  "poll_detik": 15,
  "layar": "main",
  "perangkat": { "id": "...", "nama": "Xiaomi TV A 43", "bypass_sampai_ms": null },
  "unit": { "id": "...", "kode": "TV2", "nama": "TV 2 - PS4", "status": "main" },
  "sesi": {
    "mode": "paket", "status": "berjalan", "paket": "Paket 3 Jam PS4",
    "mulai_ms": 0, "berakhir_ms": 0, "dijeda_ms": null, "selesai_ms": null,
    "total_jeda_detik": 0, "sisa_detik": 10800, "durasi_detik": 0,
    "tarif_per_jam": null, "versi_tagihan": 1,
    "tagihan": { "nomor": "BIL-...", "pelanggan": null, "total": 20000, "estimasi_sewa": null, "lunas": false }
  },
  "pengaturan": { "posisi_timer": "kanan_atas", "peringatan_menit": 5, "transparansi_lock": 85, "durasi_bypass_menit": 15 },
  "tema": { "nama_rental": "...", "cabang": "...", "logo_url": null, "mode": "gelap", "aksen": "#..." },
  "realtime": { "key": "...", "host": "192.168.1.10", "port": 8080, "scheme": "http",
                "channel": "private-tv.<id>", "event": ".segarkan", "auth_url": "http://.../api/tv/broadcasting/auth" }
}
```

**`layar` menentukan tampilan TV** — TV tidak menghitung sendiri:

| layar | Tampilan TV |
|---|---|
| `belum_ada_unit` | Info "menunggu penugasan unit" |
| `kunci` | Layar kunci (logo, "Silakan ke kasir") |
| `main` | Buka ke HDMI PS + overlay timer |
| `jeda` | Layar jeda, timer berhenti |
| `habis` | Waktu paket habis → kunci + "Waktu habis" |
| `menunggu_bayar` | Kunci + tagihan |
| `servis` | Layar "Dalam perbaikan" |
| `bypass` | Terbuka sementara sampai `bypass_sampai_ms` |

**Timer dihitung lokal** dari `server_time_ms` (selisih jam TV vs server dihitung sekali):
- paket: `sisa = berakhir_ms - sekarang` (saat `jeda`, pakai `dijeda_ms` sebagai sekarang)
- open: `jalan = (sekarang - mulai_ms)/1000 - total_jeda_detik`

Saat sisa paket mencapai 0, TV langsung mengunci sendiri tanpa menunggu server.

## 2b. Failover server lokal ↔ cloud

`GET /status` menyertakan `server: { lokal, cloud, asal }` (diatur di Admin → Operasional → Server lokal & cloud).
TV menyimpan kedua alamat dan:

- memakai **lokal** sebagai utama; setelah 3 kegagalan beruntun (±30 detik) pindah ke **cloud**;
- selama di cloud, cek lokal tiap 2 menit dan kembali begitu lokal menjawab;
- jawaban `401` dari server **selain** server tempat TV dipasangkan dianggap "server belum tersinkron"
  (tidak menghapus pairing). Token TV berlaku di kedua server setelah data tersinkron (tahap 12).

## 3. Realtime (Reverb, protokol Pusher)

1. Sambung ke `ws(s)://host:port/app/<key>?protocol=7&client=tv-agent&version=1`
2. Setelah `pusher:connection_established`, ambil `socket_id`, lalu
   `POST auth_url { socket_id, channel_name }` (dengan Bearer token) → `{ "auth": "key:signature" }`
3. Kirim `pusher:subscribe { channel, auth }`
4. Event `segarkan` (`{ alasan, waktu_ms }`) → panggil ulang `GET /status`

Jika websocket putus, TV tetap memanggil `/status` tiap `poll_detik`.

## 4. Kode darurat (offline)

Jika server tidak bisa dihubungi, kasir melihat kode di panel (**Perangkat TV → Kode darurat**) dan mengetiknya di TV.
TOTP RFC 6238: HMAC-SHA1, periode **300 detik**, 6 digit, kunci = `rahasia_offline` (teks apa adanya).
Terima periode sekarang & satu periode sebelumnya. Vektor uji: rahasia `12345678901234567890`, waktu 599 → `287082`.

## 5. Update APK

Admin platform (super admin) mengunggah rilis di **Platform → Rilis APK TV**. Setiap rilis baru
mengirim event `segarkan` dengan `alasan: "update"` ke semua TV.

TV memeriksa saat aplikasi dibuka, tiap 6 jam, dan saat menerima `alasan: "update"`:

```
GET /api/tv/update?versi_kode=<versionCode APK terpasang>

200 { "ada_update": false }
200 { "ada_update": true, "versi_nama": "0.2.0", "versi_kode": 2, "ukuran": 8123456,
      "sha256": "...", "wajib": false, "catatan": "...", "url": "http://.../api/tv/update/<id>/unduh" }
```

Unduh `url` dengan header `Authorization: Bearer <token>` (maks. 10 unduhan/menit).
**Wajib cocokkan SHA-256 file** sebelum memasang. `wajib: true` → TV tidak boleh dipakai sampai update terpasang;
selain itu pasang saat layar `kunci` (tidak ada sesi berjalan).
