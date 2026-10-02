# Billing Rental PS

Aplikasi billing rental PlayStation: kasir (tablet), panel admin, TV Agent (APK Android TV), halaman publik,
pembayaran online (QRIS), sinkronisasi server lokal ↔ cloud.

Stack: Laravel 13 · Livewire 4 · Filament 5 · Spatie Permission · Reverb · MariaDB · APK Kotlin (`tv-agent/`).

## Dokumen

| Dokumen | Isi |
|---|---|
| [docs/changelogbill.txt](docs/changelogbill.txt) | Catatan update aplikasi billing |
| [docs/changelog.txt](docs/changelog.txt) | Catatan update APK TV per versi |
| [docs/hak-akses.md](docs/hak-akses.md) | Perbedaan akses Super Admin, Owner, Supervisor, Kasir, Teknisi |
| [docs/pembayaran-online.md](docs/pembayaran-online.md) | Payment gateway & bayar mandiri QRIS di TV |
| [docs/turnamen.md](docs/turnamen.md) | Format turnamen (gugur, gugur ganda, liga, grup), bundling F&B, keuangan & hadiah |
| [docs/sinkron.md](docs/sinkron.md) | Sinkronisasi server lokal ↔ cloud, token |
| [docs/installer.md](docs/installer.md) | Installer Windows PC rental: pasang, update, uninstall, build `.exe` |
| [docs/pentest-2026-10-02.md](docs/pentest-2026-10-02.md) | Hasil pentest sebelum publik + checklist wajib |
| [docs/hosting.md](docs/hosting.md) | Pasang server cloud di hosting cPanel / shared hosting (tanpa realtime TV) |
| [docs/cloudpanel.md](docs/cloudpanel.md) | Langkah pasang server cloud di CloudPanel (Nginx, Cloudflare, cron, supervisor, update) |
| [docs/server-publik.md](docs/server-publik.md) | Server cloud, Cloudflare, port, `.env` produksi, checklist publik |
| [docs/rilis-apk.md](docs/rilis-apk.md) | Rilis, push update & rollback APK TV |
| [docs/tv-agent-api.md](docs/tv-agent-api.md) | Kontrak API server ↔ TV Agent |
| [docs/desain/README.md](docs/desain/README.md) | Desain Stitch (acuan semua tampilan) |

## Menjalankan (pengembangan)

```bash
composer install && npm install && npm run build
php artisan migrate --seed          # data demo: admin@billing.test / owner@billing.test, password "password"
php artisan serve                   # http://127.0.0.1:8000
php artisan reverb:start            # realtime
php artisan queue:work              # antrean
php artisan schedule:work           # pembayaran online, sync, backup, dll.
php artisan test                    # tes (database billing_ps_test)
```

Perintah berguna: `php artisan superadmin` (buat/reset super admin) · `php artisan sync` ·
`php artisan backup:buat` · `php artisan gambar:kompres`.
Setelah update aplikasi: Admin → Pengaturan → **Pemeliharaan sistem**.

APK TV: `tv-agent/` (Android Studio / `gradlew assembleDebug`). Rilis & rollback: `docs/rilis-apk.md`.
