<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental PC di samping rental PS:
 * - tipe_konsol.jenis: ps | pc → menu kasir "Rental PS" & "Rental PC" (paket harga tetap per tipe)
 * - perangkat_tv.jenis: tv | pc → PC Windows (agen kiosk) memakai API & pairing yang sama dengan TV Agent
 * - perangkat_tv.mac: dilaporkan agen PC, untuk Wake-on-LAN (nyalakan PC dari kasir)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tipe_konsol', 'jenis')) {
            Schema::table('tipe_konsol', function (Blueprint $table) {
                $table->string('jenis', 10)->default('ps')->after('kode');
            });
        }

        if (! Schema::hasColumn('perangkat_tv', 'jenis')) {
            Schema::table('perangkat_tv', function (Blueprint $table) {
                $table->string('jenis', 10)->default('tv')->after('unit_id');
                $table->string('mac', 17)->nullable()->after('ip');
            });
        }

        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        if (Schema::hasColumn('tipe_konsol', 'jenis')) {
            Schema::table('tipe_konsol', fn (Blueprint $table) => $table->dropColumn('jenis'));
        }

        if (Schema::hasColumn('perangkat_tv', 'jenis')) {
            Schema::table('perangkat_tv', fn (Blueprint $table) => $table->dropColumn(['jenis', 'mac']));
        }

        DaftarTabel::pasangTrigger();
    }
};
