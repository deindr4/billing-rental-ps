<?php

return [
    // Service WhatsApp lokal (folder whatsapp-service, login via QR)
    'whatsapp' => [
        'url' => env('WA_SERVICE_URL', 'http://127.0.0.1:3001'),
        'token' => env('WA_SERVICE_TOKEN', ''),
    ],

    // Proxy tepercaya (Cloudflare Tunnel / reverse proxy) supaya IP pengunjung & https terbaca benar.
    // Cloudflare Tunnel di server yang sama: "127.0.0.1,::1". Kosong = tidak ada proxy (server LAN biasa).
    // Jangan "*" jika server bisa diakses langsung tanpa proxy (IP bisa dipalsukan lewat header).
    'proxy_tepercaya' => env('TRUSTED_PROXIES'),

    // TV Agent: alamat Reverb yang dipakai TV di jaringan LAN.
    // Kosong = pakai host yang dipakai TV saat memanggil API (biasanya IP server).
    'tv' => [
        'ws_host' => env('TV_WS_HOST'),
        'ws_port' => env('TV_WS_PORT'),
        'ws_scheme' => env('TV_WS_SCHEME'),
    ],
];
