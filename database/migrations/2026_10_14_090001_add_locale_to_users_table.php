<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bahasa pilihan pengguna (null = ikut bawaan cabang). Bawaan cabang disimpan di Pengaturan umum.bahasa. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'locale')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('locale', 5)->nullable()->after('pin');
            });
        }

        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'locale')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('locale'));
        }

        DaftarTabel::pasangTrigger();
    }
};
