# Skrip pemasangan CloudPanel

Otomatisasi langkah 4 & 6 di [docs/cloudpanel.md](../../docs/cloudpanel.md). Langkah 1–3 (Cloudflare, buat site Laravel,
buat database) dan 5 (blok Nginx di Vhost) tetap lewat panel.

| Skrip | Dijalankan sebagai | Isi |
|---|---|---|
| `pasang-site.sh` | site user | Pertama kali: ekstrak paket ke folder site (bukan `public`), buat `.env` (database ditanya, nilai acak otomatis), cek database, migrasi, hak akses, trigger sinkron, super admin, optimize. Bila `.env` sudah ada: **mode update** (down → backup → ekstrak → migrasi → optimize → up). |
| `pasang-root.sh` | root | `log_bin_trust_function_creators=1` (MySQL 8 / MariaDB) & supervisor: antrean, realtime TV (Reverb), jadwal (`schedule:work`, pengganti cron). |

Ganti domain / user / PHP lewat variabel: `DOMAIN=... SITE_USER=... PHP=... bash pasang-root.sh`
(bawaan: `cloudbill.deltagamesbali.id`, site user dideteksi dari /home/<user>/htdocs/<domain>, PHP 8.4).

```bash
# site user
cd ~ && wget -q https://raw.githubusercontent.com/deindr4/billing-rental-ps/master/installer/cloudpanel/pasang-site.sh
bash pasang-site.sh                 # paket BillingPS-cloud-*.tar.gz dicari otomatis di ~ / folder site

# root
wget -q https://raw.githubusercontent.com/deindr4/billing-rental-ps/master/installer/cloudpanel/pasang-root.sh
bash pasang-root.sh
```
