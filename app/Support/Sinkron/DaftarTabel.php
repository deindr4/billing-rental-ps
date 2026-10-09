<?php

namespace App\Support\Sinkron;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel yang disinkronkan lokal <-> cloud dan cara mencatat perubahannya (trigger MySQL/MariaDB).
 *
 * Jenis:
 *  - baris   : tabel ber-id uuid + tenant_id; dikirim per baris (upsert / hapus)
 *  - tenant  : tabel tenants (tenant = id)
 *  - role    : roles & role_has_permissions; dikirim per role, izin dicocokkan lewat NAMA
 *  - pivot   : tabel penghubung; dikirim satu set per induk (hapus lalu isi ulang)
 *
 * Tambah tabel baru di sini lalu jalankan `php artisan sync:pasang-trigger`.
 */
final class DaftarTabel
{
    /** Tabel data biasa (id uuid + tenant_id) */
    public const BARIS = [
        'cabang', 'users', 'karyawan',
        'tipe_konsol', 'kategori_unit', 'games', 'units', 'paket_harga', 'aturan_harga', 'aksesori', 'pengaturan',
        'kategori_produk', 'produk', 'produk_stok', 'stok_mutasi',
        'shifts', 'kas_mutasi', 'transaksi', 'transaksi_item', 'transaksi_diskon', 'pembayaran',
        'sesi', 'sesi_log', 'sesi_aksesori', 'pengeluaran',
        'perangkat_tv', 'log_tv', 'notifikasi_log',
        'members', 'member_mutasi',
        'aset', 'maintenance', 'modal_mutasi',
        'antrean_lounge', 'booking', 'turnamen', 'turnamen_peserta', 'turnamen_pertandingan', 'iklan', 'pembayaran_online',
        'audit_log',
    ];

    /** Tabel yang hanya ditambah (tidak pernah diubah): dikirim dengan INSERT IGNORE */
    public const HANYA_TAMBAH = ['audit_log'];

    /** Pivot: tabel => [kolom induk, tabel induk (untuk tenant)] */
    public const PIVOT = [
        'cabang_user' => ['user_id', 'users'],
        'game_unit' => ['unit_id', 'units'],
        'model_has_roles' => ['model_id', null],       // punya kolom tenant_id sendiri
        'model_has_permissions' => ['model_id', null],
    ];

    /** Semua nama "tabel" yang boleh muncul di paket sinkron */
    public static function semua(): array
    {
        return array_merge(self::BARIS, ['tenants', 'roles'], array_keys(self::PIVOT));
    }

    public static function boleh(string $tabel): bool
    {
        return in_array($tabel, self::semua(), true);
    }

    /** @return array<int, string> daftar pernyataan CREATE TRIGGER */
    public static function trigger(): array
    {
        $sql = [];
        $catat = fn (string $tabel, string $id, string $aksi, string $tenant) => 'IF @sync_lewati IS NULL THEN '
            ."INSERT INTO sync_antrean (tabel, row_id, aksi, tenant_id, sumber, created_at) VALUES ('{$tabel}', {$id}, '{$aksi}', {$tenant}, @sync_sumber, NOW(3)); "
            .'END IF';

        foreach (self::BARIS as $t) {
            if (! Schema::hasTable($t)) {
                continue;
            }

            $sql[] = "CREATE TRIGGER sync_{$t}_ai AFTER INSERT ON `{$t}` FOR EACH ROW ".$catat($t, 'NEW.id', 'upsert', 'NEW.tenant_id');
            $sql[] = "CREATE TRIGGER sync_{$t}_au AFTER UPDATE ON `{$t}` FOR EACH ROW ".$catat($t, 'NEW.id', 'upsert', 'NEW.tenant_id');
            $sql[] = "CREATE TRIGGER sync_{$t}_ad AFTER DELETE ON `{$t}` FOR EACH ROW ".$catat($t, 'OLD.id', 'hapus', 'OLD.tenant_id');
        }

        $sql[] = 'CREATE TRIGGER sync_tenants_ai AFTER INSERT ON `tenants` FOR EACH ROW '.$catat('tenants', 'NEW.id', 'upsert', 'NEW.id');
        $sql[] = 'CREATE TRIGGER sync_tenants_au AFTER UPDATE ON `tenants` FOR EACH ROW '.$catat('tenants', 'NEW.id', 'upsert', 'NEW.id');

        $sql[] = 'CREATE TRIGGER sync_roles_ai AFTER INSERT ON `roles` FOR EACH ROW '.$catat('roles', 'NEW.id', 'upsert', 'NEW.tenant_id');
        $sql[] = 'CREATE TRIGGER sync_roles_au AFTER UPDATE ON `roles` FOR EACH ROW '.$catat('roles', 'NEW.id', 'upsert', 'NEW.tenant_id');
        $sql[] = 'CREATE TRIGGER sync_roles_ad AFTER DELETE ON `roles` FOR EACH ROW '.$catat('roles', 'OLD.id', 'hapus', 'OLD.tenant_id');

        $tenantRole = fn (string $r) => "(SELECT tenant_id FROM roles WHERE id = {$r}.role_id)";
        $sql[] = 'CREATE TRIGGER sync_role_has_permissions_ai AFTER INSERT ON `role_has_permissions` FOR EACH ROW '.$catat('roles', 'NEW.role_id', 'upsert', $tenantRole('NEW'));
        $sql[] = 'CREATE TRIGGER sync_role_has_permissions_ad AFTER DELETE ON `role_has_permissions` FOR EACH ROW '.$catat('roles', 'OLD.role_id', 'upsert', $tenantRole('OLD'));

        foreach (self::PIVOT as $t => [$induk, $tabelInduk]) {
            $tenant = fn (string $r) => $tabelInduk ? "(SELECT tenant_id FROM {$tabelInduk} WHERE id = {$r}.{$induk})" : "{$r}.tenant_id";

            $sql[] = "CREATE TRIGGER sync_{$t}_ai AFTER INSERT ON `{$t}` FOR EACH ROW ".$catat($t, "NEW.{$induk}", 'pivot', $tenant('NEW'));
            $sql[] = "CREATE TRIGGER sync_{$t}_au AFTER UPDATE ON `{$t}` FOR EACH ROW ".$catat($t, "NEW.{$induk}", 'pivot', $tenant('NEW'));
            $sql[] = "CREATE TRIGGER sync_{$t}_ad AFTER DELETE ON `{$t}` FOR EACH ROW ".$catat($t, "OLD.{$induk}", 'pivot', $tenant('OLD'));
        }

        return $sql;
    }

    /** Pasang ulang semua trigger sinkron (aman dijalankan berulang) */
    public static function pasangTrigger(): int
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return 0;
        }

        self::lepasTrigger();

        $sql = self::trigger();

        foreach ($sql as $s) {
            DB::unprepared($s);
        }

        return count($sql);
    }

    public static function lepasTrigger(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $nama = collect(DB::select('SHOW TRIGGERS'))->pluck('Trigger')->filter(fn ($n) => str_starts_with($n, 'sync_'));

        foreach ($nama as $n) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$n}`");
        }
    }
}
