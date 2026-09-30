<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignUuid('transaksi_id')->constrained('transaksi')->restrictOnDelete();
            $table->foreignUuid('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('paket_harga_id')->nullable()->constrained('paket_harga')->restrictOnDelete();

            $table->string('mode', 10); // paket | open
            $table->unsignedBigInteger('tarif_per_jam')->nullable();
            $table->unsignedInteger('durasi_menit')->nullable(); // total durasi paket + tambahan

            $table->timestamp('mulai_pada');
            $table->timestamp('berakhir_pada')->nullable(); // rencana selesai (paket)
            $table->timestamp('selesai_pada')->nullable();  // selesai sebenarnya
            $table->timestamp('dijeda_pada')->nullable();
            $table->unsignedInteger('total_jeda_detik')->default(0);
            $table->unsignedSmallInteger('jumlah_jeda')->default(0);

            $table->string('status', 20)->default('berjalan'); // berjalan | dijeda | selesai | dibatalkan
            $table->boolean('bayar_di_awal')->default(false);
            $table->unsignedInteger('versi_tagihan')->default(1);

            $table->timestamps();
            $table->syncColumns();

            $table->index(['cabang_id', 'status']);
            $table->index(['unit_id', 'status']);
            $table->index('transaksi_id');
        });

        Schema::create('sesi_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('sesi_id')->constrained('sesi')->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();

            // mulai | tambah_waktu | pause | resume | pindah_unit | selesai | bayar | batal
            $table->string('jenis', 30);
            $table->json('data')->nullable();

            $table->timestamps();
            $table->syncColumns();

            $table->index(['sesi_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesi_log');
        Schema::dropIfExists('sesi');
    }
};
