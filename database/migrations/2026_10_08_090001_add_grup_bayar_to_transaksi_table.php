<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bayar gabungan antar unit: beberapa tagihan (mis. TV1 + TV2 + POS) dibayar sekaligus. Tiap tagihan tetap
 * tercatat sendiri (laporan per unit benar); grup_bayar mengikat mereka untuk satu struk gabungan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transaksi', 'grup_bayar')) {
            Schema::table('transaksi', function (Blueprint $table) {
                $table->uuid('grup_bayar')->nullable()->after('status')->index();
            });
        }

        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        if (Schema::hasColumn('transaksi', 'grup_bayar')) {
            Schema::table('transaksi', function (Blueprint $table) {
                $table->dropIndex(['grup_bayar']);
                $table->dropColumn('grup_bayar');
            });
        }

        DaftarTabel::pasangTrigger();
    }
};
