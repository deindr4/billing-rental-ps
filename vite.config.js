import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                // Operator: Rental, POS, Kas, Transaksi, Stok, Laporan, Login
                'resources/css/operator.css',
                'resources/js/operator.js',

                // Halaman Laporan di panel admin
                'resources/css/admin-laporan.css',

                // Billboard publik (TV lounge)
                'resources/css/billboard.css',
                'resources/js/billboard.js',

                // Portal booking publik
                'resources/css/booking.css',
                'resources/js/booking.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        cssMinify: 'lightningcss',
        chunkSizeWarningLimit: 300,
    },
});
