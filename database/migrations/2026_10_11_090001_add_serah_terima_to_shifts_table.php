<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu laci per cabang + serah terima shift antar kasir:
 * - kas_ditinggal: uang yang ditinggal di laci (modal kembalian untuk shift berikutnya)
 * - setoran: kas fisik - kas_ditinggal, disetor ke owner / brankas
 * - diserahkan_ke + shift_berikut_id: serah terima ke kasir lain (shift baru langsung dibuka)
 * - selisih_terima (di shift baru): hitungan penerima - kas yang ditinggal penyerah
 * - serah_terima: potret sesi masih main & tagihan belum dibayar saat diserahkan (JSON)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shifts', 'kas_ditinggal')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->bigInteger('kas_ditinggal')->nullable()->after('selisih');
                $table->bigInteger('setoran')->nullable()->after('kas_ditinggal');
                $table->foreignUuid('diserahkan_ke')->nullable()->after('ditutup_oleh')->constrained('users')->nullOnDelete();
                $table->uuid('shift_sebelum_id')->nullable()->after('diserahkan_ke');
                $table->uuid('shift_berikut_id')->nullable()->after('shift_sebelum_id');
                $table->bigInteger('selisih_terima')->nullable()->after('shift_berikut_id');
                $table->json('serah_terima')->nullable()->after('selisih_terima');
            });
        }

        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        if (Schema::hasColumn('shifts', 'kas_ditinggal')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('diserahkan_ke');
                $table->dropColumn(['kas_ditinggal', 'setoran', 'shift_sebelum_id', 'shift_berikut_id', 'selisih_terima', 'serah_terima']);
            });
        }

        DaftarTabel::pasangTrigger();
    }
};
