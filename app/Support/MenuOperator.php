<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Satu-satunya tempat mendaftarkan menu operator.
 * Menu tampil jika route-nya ada DAN user punya izinnya.
 */
final class MenuOperator
{
    /**
     * warna = kelas warna ikon (token --ikon-* di tokens.css), supaya menu mudah dibedakan sekilas.
     *
     * @return array<string, array<int, array{route:string, label:string, ikon:string, warna:string, izin:string}>>
     */
    public static function daftar(): array
    {
        return [
            'Kasir' => [
                ['route' => 'rental', 'label' => 'Rental', 'ikon' => 'rental', 'warna' => 'text-ik-biru', 'izin' => 'rental.kelola'],
                ['route' => 'pos', 'label' => 'POS & F&B', 'ikon' => 'pos', 'warna' => 'text-ik-oranye', 'izin' => 'pos.jual'],
                ['route' => 'jadwal', 'label' => 'Jadwal & Booking', 'ikon' => 'jadwal', 'warna' => 'text-ik-ungu', 'izin' => 'rental.kelola'],
                ['route' => 'lounge', 'label' => 'Billboard', 'ikon' => 'lounge', 'warna' => 'text-ik-pink', 'izin' => 'rental.kelola'],
                ['route' => 'turnamen', 'label' => 'Turnamen', 'ikon' => 'turnamen', 'warna' => 'text-ik-kuning', 'izin' => 'turnamen.kelola'],
                ['route' => 'pembayaran-online', 'label' => 'Pembayaran online', 'ikon' => 'bayar', 'warna' => 'text-ik-hijau', 'izin' => 'pembayaran.terima'],
                ['route' => 'transaksi', 'label' => 'Transaksi', 'ikon' => 'transaksi', 'warna' => 'text-ik-teal', 'izin' => 'transaksi.lihat'],
                ['route' => 'member', 'label' => 'Member', 'ikon' => 'member', 'warna' => 'text-ik-indigo', 'izin' => 'member.kelola'],
            ],
            'Operasional' => [
                ['route' => 'stok', 'label' => 'Stok', 'ikon' => 'stok', 'warna' => 'text-ik-teal', 'izin' => 'stok.lihat'],
                ['route' => 'pengeluaran', 'label' => 'Pengeluaran', 'ikon' => 'kas', 'warna' => 'text-ik-merah', 'izin' => 'pengeluaran.catat'],
                ['route' => 'maintenance', 'label' => 'Maintenance', 'ikon' => 'maintenance', 'warna' => 'text-ik-oranye', 'izin' => 'maintenance.kelola'],
                ['route' => 'aset', 'label' => 'Aset & Modal', 'ikon' => 'aset', 'warna' => 'text-ik-hijau', 'izin' => 'aset.lihat'],
                ['route' => 'laporan', 'label' => 'Laporan', 'ikon' => 'laporan', 'warna' => 'text-ik-biru', 'izin' => 'laporan.lihat'],
            ],
        ];
    }

    /** Menu per grup yang tersedia untuk user ini, lengkap dengan status aktif. */
    public static function tersedia(): array
    {
        $user = auth()->user();
        $hasil = [];

        foreach (self::daftar() as $grup => $items) {
            $items = array_values(array_filter(
                array_map(fn ($m) => $m + [
                    'aktif' => request()->routeIs($m['route'], $m['route'].'.*'),
                ], $items),
                fn ($m) => Route::has($m['route']) && $user?->can($m['izin'])
            ));

            if ($items !== []) {
                $hasil[$grup] = $items;
            }
        }

        return $hasil;
    }

    /** 3 menu utama untuk navigasi bawah di HP. */
    public static function bawah(): array
    {
        return array_slice(array_merge(...array_values(self::tersedia() ?: [[]])), 0, 3);
    }
}
