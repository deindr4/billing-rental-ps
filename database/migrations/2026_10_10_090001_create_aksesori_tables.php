<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sewa aksesori (stik tambahan, headset, setir) di lokasi, ditagih ke sesi unit:
 * - aksesori: master per cabang, harga flat per sesi atau per jam, jumlah stok
 * - sesi_aksesori: aksesori yang sedang / pernah disewa sebuah sesi (selesai_pada null = masih dipakai)
 * Juga: units.warna = warna penanda kartu unit (kosong = otomatis dari palet).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('aksesori')) {
            Schema::create('aksesori', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
                $table->string('nama', 60);
                $table->unsignedBigInteger('harga');
                $table->string('satuan', 10)->default('sesi'); // sesi | jam
                $table->unsignedInteger('stok')->default(1);
                $table->unsignedInteger('urutan')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->syncColumns();
            });
        }

        if (! Schema::hasTable('sesi_aksesori')) {
            Schema::create('sesi_aksesori', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
                $table->foreignUuid('sesi_id')->constrained('sesi')->restrictOnDelete();
                $table->foreignUuid('aksesori_id')->constrained('aksesori')->restrictOnDelete();
                $table->foreignUuid('transaksi_item_id')->nullable()->constrained('transaksi_item')->restrictOnDelete();
                $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('qty');
                $table->unsignedBigInteger('harga');   // harga saat disewa (per sesi / per jam)
                $table->string('satuan', 10);
                // DATETIME, bukan TIMESTAMP: TIMESTAMP NOT NULL pertama bisa mendapat ON UPDATE CURRENT_TIMESTAMP
                // di MariaDB (jam mulai sewa tergeser saat baris diperbarui) — lihat 2026_10_04_090001
                $table->dateTime('mulai_pada');
                $table->dateTime('selesai_pada')->nullable();
                $table->boolean('dibatalkan')->default(false);
                $table->timestamps();
                $table->syncColumns();

                $table->index(['aksesori_id', 'selesai_pada']);
                $table->index('sesi_id');
            });
        }

        if (! Schema::hasColumn('units', 'warna')) {
            Schema::table('units', function (Blueprint $table) {
                $table->string('warna', 7)->nullable()->after('urutan');
            });
        }

        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        Schema::dropIfExists('sesi_aksesori');
        Schema::dropIfExists('aksesori');

        if (Schema::hasColumn('units', 'warna')) {
            Schema::table('units', fn (Blueprint $table) => $table->dropColumn('warna'));
        }

        DaftarTabel::pasangTrigger();
    }
};
