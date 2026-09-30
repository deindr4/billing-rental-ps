<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->foreignUuid('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();

            $table->string('nomor', 40);
            $table->string('jenis', 20);  // billing | pos | top_up | turnamen
            $table->string('status', 20)->default('belum_bayar'); // belum_bayar | lunas | dibatalkan

            $table->uuid('member_id')->nullable(); // relasi ke member ditambahkan di tahap member
            $table->string('pelanggan_nama', 100)->nullable();

            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('total_diskon')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('total_bayar')->default(0);
            $table->unsignedBigInteger('kembalian')->default(0);

            $table->timestamp('dibayar_pada')->nullable();
            $table->timestamp('dibatalkan_pada')->nullable();
            $table->foreignUuid('dibatalkan_oleh')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('alasan_batal')->nullable();

            $table->text('catatan')->nullable();
            $table->boolean('is_latihan')->default(false);

            $table->timestamps();
            $table->syncColumns();

            $table->unique(['tenant_id', 'nomor']);
            $table->index(['cabang_id', 'created_at']);
            $table->index(['cabang_id', 'status', 'created_at']);
            $table->index(['cabang_id', 'jenis', 'created_at']);
            $table->index('shift_id');
            $table->index(['unit_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['member_id', 'created_at']);
        });

        Schema::create('transaksi_item', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('transaksi_id')->constrained('transaksi')->restrictOnDelete();

            $table->string('jenis', 20); // sewa | tambah_waktu | produk | lainnya
            $table->nullableUuidMorphs('referensi');
            $table->string('nama', 150);
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedBigInteger('harga_satuan');
            $table->unsignedBigInteger('subtotal');
            $table->text('catatan')->nullable();

            $table->timestamps();
            $table->syncColumns();

            $table->index('transaksi_id');
        });

        Schema::create('transaksi_diskon', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('transaksi_id')->constrained('transaksi')->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->string('jenis', 20); // member | voucher | promo | poin | stamp | manual
            $table->string('nama', 100);
            $table->unsignedBigInteger('nilai');
            $table->nullableUuidMorphs('referensi');

            $table->timestamps();
            $table->syncColumns();

            $table->index('transaksi_id');
        });

        Schema::create('pembayaran', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('transaksi_id')->constrained('transaksi')->restrictOnDelete();
            $table->foreignUuid('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();

            $table->string('metode', 20); // tunai | qris | transfer | saldo
            $table->unsignedBigInteger('jumlah');   // nominal yang dipakai untuk tagihan
            $table->unsignedBigInteger('diterima')->nullable(); // uang diterima (tunai)
            $table->string('referensi', 100)->nullable();
            $table->string('status', 20)->default('sukses'); // sukses | dibatalkan
            $table->timestamp('dibayar_pada');

            $table->timestamps();
            $table->syncColumns();

            $table->index('transaksi_id');
            $table->index(['shift_id', 'metode']);
            $table->index(['cabang_id', 'dibayar_pada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembayaran');
        Schema::dropIfExists('transaksi_diskon');
        Schema::dropIfExists('transaksi_item');
        Schema::dropIfExists('transaksi');
    }
};
