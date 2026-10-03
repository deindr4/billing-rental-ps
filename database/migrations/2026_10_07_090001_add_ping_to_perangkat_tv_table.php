<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Respon (ms) TV ke server lokal & cloud + server yang sedang dipakai, dilaporkan lewat heartbeat (APK >= 0.6.5).
 * -1 = tidak terjangkau, null = belum diukur / server cloud tidak diatur.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('perangkat_tv', 'ping_lokal_ms')) {
            Schema::table('perangkat_tv', function (Blueprint $table) {
                $table->integer('ping_lokal_ms')->nullable()->after('layar_hidup');
                $table->integer('ping_cloud_ms')->nullable()->after('ping_lokal_ms');
                $table->string('server_dipakai', 10)->nullable()->after('ping_cloud_ms');
            });
        }

        // Trigger sinkron membaca daftar kolom saat dipasang: pasang ulang supaya kolom baru ikut tersinkron
        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        if (Schema::hasColumn('perangkat_tv', 'ping_lokal_ms')) {
            Schema::table('perangkat_tv', function (Blueprint $table) {
                $table->dropColumn(['ping_lokal_ms', 'ping_cloud_ms', 'server_dipakai']);
            });
        }

        DaftarTabel::pasangTrigger();
    }
};
