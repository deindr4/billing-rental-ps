<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perangkat_tv', function (Blueprint $table) {
            // Laporan kemampuan TV dari APK (izin overlay, input HDMI, launcher, dll.)
            $table->json('diagnostik')->nullable()->after('layar');
            $table->timestamp('diagnostik_pada')->nullable()->after('diagnostik');
        });
    }

    public function down(): void
    {
        Schema::table('perangkat_tv', function (Blueprint $table) {
            $table->dropColumn(['diagnostik', 'diagnostik_pada']);
        });
    }
};
