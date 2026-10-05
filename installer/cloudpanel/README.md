# Skrip pemasangan CloudPanel

Otomatisasi langkah 4 & 6 di [docs/cloudpanel.md](../../docs/cloudpanel.md). Langkah 1–3 (Cloudflare, buat site Laravel,
buat database) dan 5 (blok Nginx di Vhost) tetap lewat panel.

| Skrip | Dijalankan sebagai | Isi |
|---|---|---|
| `pasang-site.sh` | site user | Pertama kali: ekstrak paket ke folder site (bukan `public`), buat `.env` (database ditanya, nilai acak otomatis), cek database, migrasi, hak akses, trigger sinkron, super admin, optimize. Bila `.env` sudah ada: **mode update** (down → backup → ekstrak → migrasi → optimize → up). |
| `pasang-root.sh` | root | `log_bin_trust_function_creators=1` (MySQL 8 / MariaDB) & supervisor: antrean, realtime TV (Reverb), jadwal (`schedule:work`, pengganti cron). |

## Vhost CloudPanel (template dengan Varnish)

Template ini punya dua blok `server`: depan (443, menyajikan file statis langsung dari disk lalu meneruskan sisanya ke
Varnish) dan backend (**8080**, PHP). Karena itu:

- Reverb memakai **6001** (bukan 8080) — diatur skrip.
- JS Livewire dipublikasikan ke `public/vendor/livewire` (paket & skrip) — kalau tidak, `/livewire-xxx/livewire.min.js`
  404 dan tombol login tidak bereaksi.
- `TRUSTED_PROXIES=127.0.0.1,::1,cloudflare` — PHP menerima permintaan dari Nginx/Varnish lokal.
- Disarankan **matikan Varnish** untuk situs ini (Site → Varnish Cache → nonaktif): halaman aplikasi dinamis & per login.

Tambahan di Vhost — blok **443**, sebelum `location / {`:

```nginx
  client_max_body_size 256M;

  location /app {
    proxy_pass http://127.0.0.1:6001;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600;
  }
```

Blok **8080**, sebelum `location ~ \.php$ {`:

```nginx
  client_max_body_size 256M;

  location ~* ^/storage/.*\.(php\d?|phtml|phar|pht)$ {
    deny all;
  }
```

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
