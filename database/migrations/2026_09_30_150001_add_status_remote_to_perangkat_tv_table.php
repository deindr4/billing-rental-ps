<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perangkat_tv', function (Blueprint $table) {
            // Dilaporkan TV lewat heartbeat, ditampilkan di tombol remote kasir
            $table->unsignedTinyInteger('volume')->nullable()->after('layar'); // 0-100
            $table->boolean('senyap')->nullable()->after('volume');
            $table->boolean('layar_hidup')->nullable()->after('senyap');
        });
    }

    public function down(): void
    {
        Schema::table('perangkat_tv', function (Blueprint $table) {
            $table->dropColumn(['volume', 'senyap', 'layar_hidup']);
        });
    }
};
