<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kas_mutasi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();

            // kas_awal | penjualan | pengeluaran | top_up | pembatalan | koreksi | setor
            $table->string('jenis', 20);
            $table->bigInteger('jumlah'); // + masuk laci, - keluar laci
            $table->nullableUuidMorphs('sumber');
            $table->string('keterangan')->nullable();

            $table->timestamps();
            $table->syncColumns();

            $table->index('shift_id');
            $table->index(['cabang_id', 'created_at']);
            $table->index(['cabang_id', 'jenis', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kas_mutasi');
    }
};
