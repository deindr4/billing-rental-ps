<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kunci di level database: data keuangan & sesi tidak bisa di-DELETE,
 * hanya bisa dibatalkan lewat aplikasi.
 */
return new class extends Migration
{
    private array $tabel = [
        'transaksi',
        'transaksi_item',
        'transaksi_diskon',
        'pembayaran',
        'kas_mutasi',
        'sesi',
        'sesi_log',
        'shifts',
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($this->tabel as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}_larangan_hapus");
            DB::unprepared(
                "CREATE TRIGGER {$t}_larangan_hapus BEFORE DELETE ON {$t} FOR EACH ROW "
                ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data {$t} tidak bisa dihapus. Gunakan pembatalan.'"
            );
        }
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($this->tabel as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}_larangan_hapus");
        }
    }
};
