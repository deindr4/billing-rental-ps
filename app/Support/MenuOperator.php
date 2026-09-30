<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Satu-satunya tempat mendaftarkan menu operator.
 * Menu tampil jika route-nya ada DAN user punya izinnya.
 */
final class MenuOperator
{
    /** @return array<string, array<int, array{route:string, label:string, ikon:string, izin:string}>> */
    public static function daftar(): array
    {
        return [
            'Kasir' => [
                ['route' => 'rental', 'label' => 'Rental', 'ikon' => 'rental', 'izin' => 'rental.kelola'],
                ['route' => 'pos', 'label' => 'POS & F&B', 'ikon' => 'pos', 'izin' => 'pos.jual'],
                ['route' => 'jadwal', 'label' => 'Jadwal & Booking', 'ikon' => 'jadwal', 'izin' => 'rental.kelola'],
                ['route' => 'lounge', 'label' => 'Billboard', 'ikon' => 'lounge', 'izin' => 'rental.kelola'],
                ['route' => 'turnamen', 'label' => 'Turnamen', 'ikon' => 'turnamen', 'izin' => 'turnamen.kelola'],
                ['route' => 'transaksi', 'label' => 'Transaksi', 'ikon' => 'transaksi', 'izin' => 'transaksi.lihat'],
                ['route' => 'member', 'label' => 'Member', 'ikon' => 'member', 'izin' => 'member.kelola'],
            ],
            'Operasional' => [
                ['route' => 'stok', 'label' => 'Stok', 'ikon' => 'stok', 'izin' => 'stok.lihat'],
                ['route' => 'pengeluaran', 'label' => 'Pengeluaran', 'ikon' => 'kas', 'izin' => 'pengeluaran.catat'],
                ['route' => 'maintenance', 'label' => 'Maintenance', 'ikon' => 'maintenance', 'izin' => 'maintenance.kelola'],
                ['route' => 'aset', 'label' => 'Aset & Modal', 'ikon' => 'aset', 'izin' => 'aset.lihat'],
                ['route' => 'laporan', 'label' => 'Laporan', 'ikon' => 'laporan', 'izin' => 'laporan.lihat'],
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
