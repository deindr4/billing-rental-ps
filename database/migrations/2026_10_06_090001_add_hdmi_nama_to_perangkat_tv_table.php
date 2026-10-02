<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nama konsol per input HDMI satu TV (mis. HDMI 1 = PS3, HDMI 2 = PS4, HDMI 3 = PS5),
 * supaya kasir memilih / memindah HDMI dengan nama yang jelas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('perangkat_tv', 'hdmi_nama')) {
            Schema::table('perangkat_tv', function (Blueprint $table) {
                $table->json('hdmi_nama')->nullable()->after('input_hdmi_label');
            });
        }

        // Trigger sinkron membaca daftar kolom saat dipasang: pasang ulang supaya kolom baru ikut tersinkron
        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        if (Schema::hasColumn('perangkat_tv', 'hdmi_nama')) {
            Schema::table('perangkat_tv', function (Blueprint $table) {
                $table->dropColumn('hdmi_nama');
            });
        }

        DaftarTabel::pasangTrigger();
    }
};
