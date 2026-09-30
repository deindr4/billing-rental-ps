<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kategori_produk', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('nama', 50);
            $table->unsignedInteger('urutan')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->syncColumns();

            $table->index(['tenant_id', 'is_active', 'urutan']);
        });

        Schema::create('produk', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('kategori_produk_id')->nullable()->constrained('kategori_produk')->nullOnDelete();

            $table->string('kode', 30)->nullable();
            $table->string('barcode', 50)->nullable();
            $table->string('nama', 100);
            $table->unsignedBigInteger('harga_jual');
            $table->string('satuan', 20)->default('pcs');
            $table->boolean('lacak_stok')->default(true);
            $table->unsignedInteger('stok_minimum')->default(0);
            $table->unsignedInteger('urutan')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->syncColumns();

            $table->index(['tenant_id', 'is_active', 'urutan']);
            $table->index(['tenant_id', 'barcode']);
            $table->index(['tenant_id', 'kode']);
        });

        // Saldo stok per cabang (cache, dihitung dari stok_mutasi)
        Schema::create('produk_stok', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('produk_id')->constrained('produk')->restrictOnDelete();
            $table->integer('qty')->default(0);
            $table->unsignedBigInteger('hpp_rata')->default(0); // harga pokok rata-rata
            $table->timestamps();

            $table->unique(['cabang_id', 'produk_id']);
        });

        // Riwayat keluar-masuk stok (tidak bisa dihapus)
        Schema::create('stok_mutasi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('produk_id')->constrained('produk')->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();

            // penjualan | pembatalan | masuk | opname | koreksi
            $table->string('jenis', 20);
            $table->integer('qty'); // + masuk, - keluar
            $table->unsignedBigInteger('harga_pokok')->nullable(); // untuk stok masuk
            $table->nullableUuidMorphs('sumber');
            $table->string('keterangan')->nullable();

            $table->timestamps();
            $table->syncColumns();

            $table->index(['produk_id', 'created_at']);
            $table->index(['cabang_id', 'created_at']);
            $table->index(['cabang_id', 'jenis', 'created_at']);
        });

        // HPP per item untuk menghitung laba kotor
        Schema::table('transaksi_item', function (Blueprint $table) {
            $table->unsignedBigInteger('hpp_satuan')->default(0)->after('harga_satuan');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS stok_mutasi_larangan_hapus');
            DB::unprepared(
                "CREATE TRIGGER stok_mutasi_larangan_hapus BEFORE DELETE ON stok_mutasi FOR EACH ROW "
                ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data stok_mutasi tidak bisa dihapus. Gunakan koreksi stok.'"
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS stok_mutasi_larangan_hapus');
        }

        Schema::table('transaksi_item', function (Blueprint $table) {
            $table->dropColumn('hpp_satuan');
        });

        Schema::dropIfExists('stok_mutasi');
        Schema::dropIfExists('produk_stok');
        Schema::dropIfExists('produk');
        Schema::dropIfExists('kategori_produk');
    }
};
