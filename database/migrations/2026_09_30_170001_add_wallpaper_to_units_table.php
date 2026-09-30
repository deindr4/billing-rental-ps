<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            // Wallpaper layar kunci TV khusus unit ini (disk public). Kosong = wallpaper default di Admin → Tampilan.
            $table->string('wallpaper')->nullable()->after('durasi_bypass_menit');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('wallpaper');
        });
    }
};
