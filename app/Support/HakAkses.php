<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;

/**
 * Daftar hak akses (permission) aplikasi dan izin bawaan tiap role.
 */
final class HakAkses
{
    /** Grup => [permission => label] */
    public const DAFTAR = [
        'Kasir' => [
            'rental.kelola' => 'Rental: mulai, kelola & selesaikan sesi',
            'pos.jual' => 'POS: jual & tambah F&B ke tagihan',
            'pembayaran.terima' => 'Terima pembayaran',
            'shift.kelola' => 'Buka & tutup kas',
            'shift.bantu' => 'Ikut bertransaksi di shift kasir lain (satu laci) & serah terima atas namanya',
        ],
        'Transaksi' => [
            'transaksi.lihat' => 'Lihat daftar transaksi',
            'transaksi.batal' => 'Menyetujui pembatalan transaksi (PIN)',
            'sesi.gratis' => 'Menyetujui tambah waktu gratis (PIN)',
        ],
        'Member' => [
            'member.kelola' => 'Daftar & ubah data member',
            'member.topup' => 'Top up saldo member',
            'member.koreksi' => 'Menyetujui koreksi saldo/poin member (PIN)',
        ],
        'Stok' => [
            'stok.lihat' => 'Lihat stok',
            'stok.masuk' => 'Catat stok masuk (belanja)',
            'stok.opname' => 'Stok opname',
        ],
        'Pengeluaran' => [
            'pengeluaran.catat' => 'Catat pengeluaran',
            'pengeluaran.lebih_plafon' => 'Menyetujui pengeluaran melebihi plafon (PIN)',
            'pengeluaran.batal' => 'Menyetujui pembatalan pengeluaran (PIN)',
        ],
        'Laporan' => [
            'laporan.lihat' => 'Lihat laporan',
            'laporan.laba' => 'Lihat HPP, margin & laba',
        ],
        'Aset' => [
            'maintenance.kelola' => 'Buat & kerjakan tiket maintenance',
            'aset.lihat' => 'Lihat aset, penyusutan & ROI',
            'aset.kelola' => 'Tambah, ubah & lepas aset',
            'modal.kelola' => 'Catat suntikan modal & prive owner',
        ],
        'Turnamen' => [
            'turnamen.kelola' => 'Buat turnamen, daftarkan peserta & input skor',
        ],
        'Audit' => [
            'audit.lihat' => 'Lihat log aktivitas (audit)',
        ],
        'TV' => [
            'tv.remote' => 'Remote TV (volume, input, daya)',
            'tv.bypass' => 'Bypass TV',
        ],
        'Admin' => [
            'admin.akses' => 'Masuk panel admin',
            'admin.master' => 'Kelola unit, paket harga & produk',
            'admin.pengguna' => 'Kelola pengguna & role',
            'admin.pengaturan' => 'Kelola pengaturan',
        ],
    ];

    /** Izin bawaan per role (Owner selalu semua izin) */
    public const BAWAAN = [
        'Supervisor' => [
            'rental.kelola', 'pos.jual', 'pembayaran.terima', 'shift.kelola', 'shift.bantu',
            'transaksi.lihat', 'transaksi.batal', 'sesi.gratis',
            'member.kelola', 'member.topup', 'member.koreksi',
            'stok.lihat', 'stok.masuk', 'stok.opname',
            'pengeluaran.catat', 'pengeluaran.lebih_plafon', 'pengeluaran.batal',
            'laporan.lihat',
            'maintenance.kelola', 'aset.lihat', 'turnamen.kelola',
            'tv.remote', 'tv.bypass',
            'admin.akses', 'admin.master',
        ],
        'Kasir' => [
            'rental.kelola', 'pos.jual', 'pembayaran.terima', 'shift.kelola',
            'transaksi.lihat',
            'member.kelola', 'member.topup',
            'stok.lihat',
            'pengeluaran.catat',
            'maintenance.kelola', 'turnamen.kelola',
        ],
        'Teknisi' => [
            'stok.lihat',
            'maintenance.kelola', 'aset.lihat',
            'tv.remote', 'tv.bypass',
        ],
    ];

    /** @return array<string,string> permission => label */
    public static function semua(): array
    {
        return array_merge(...array_values(self::DAFTAR));
    }

    public static function label(string $permission): string
    {
        foreach (self::DAFTAR as $grup => $items) {
            if (isset($items[$permission])) {
                return "{$grup} · {$items[$permission]}";
            }
        }

        return $permission;
    }

    /**
     * Buat semua permission (global). Return daftar permission yang baru dibuat,
     * supaya izin baru bisa ditambahkan ke role bawaan tanpa menimpa ubahan owner.
     *
     * @return array<int,string>
     */
    public static function siapkanPermission(): array
    {
        $baru = [];

        foreach (array_keys(self::semua()) as $nama) {
            $permission = Permission::firstOrCreate(['name' => $nama, 'guard_name' => 'web']);

            if ($permission->wasRecentlyCreated) {
                $baru[] = $nama;
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $baru;
    }

    /**
     * Siapkan role bawaan untuk tenant.
     * - Owner selalu mendapat semua izin
     * - Role baru/kosong mendapat izin bawaan
     * - Role yang sudah ada hanya ditambah izin yang BARU dibuat (ubahan owner tidak ditimpa)
     *
     * @param  array<int,string>  $izinBaru
     */
    public static function siapkanTenant(Tenant $tenant, array $izinBaru = []): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->id);

        $owner = Role::firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'Owner', 'guard_name' => 'web']);
        $owner->syncPermissions(array_keys(self::semua()));

        foreach (self::BAWAAN as $namaRole => $izin) {
            $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $namaRole, 'guard_name' => 'web']);

            if ($role->permissions()->count() === 0) {
                $role->syncPermissions($izin);

                continue;
            }

            $tambahan = array_values(array_intersect($izinBaru, $izin));

            if ($tambahan !== []) {
                $role->givePermissionTo($tambahan);
            }
        }

        $registrar->forgetCachedPermissions();
    }
}
