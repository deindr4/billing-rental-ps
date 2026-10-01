<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bonus waktu (kompensasi PS restart / hang): untuk open billing, detik bonus tidak ditagih
 * (dikurangkan dari durasi berjalan). Sesi paket memakai tambah waktu gratis seperti biasa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesi', function (Blueprint $table) {
            $table->unsignedInteger('bonus_detik')->default(0)->after('total_jeda_detik');
        });

        // Trigger sinkron membaca daftar kolom saat dipasang: pasang ulang supaya kolom baru ikut tersinkron
        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        Schema::table('sesi', function (Blueprint $table) {
            $table->dropColumn('bonus_detik');
        });

        DaftarTabel::pasangTrigger();
    }
};
