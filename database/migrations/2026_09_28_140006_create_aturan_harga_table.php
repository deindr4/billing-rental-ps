<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aturan_harga', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->nullable()->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('tipe_konsol_id')->nullable()->constrained('tipe_konsol')->restrictOnDelete();
            $table->foreignUuid('kategori_unit_id')->nullable()->constrained('kategori_unit')->restrictOnDelete();

            $table->string('nama', 100);
            $table->string('jenis', 20); // happy_hour | weekend | malam | libur | promo

            $table->json('hari')->nullable(); // [0..6], 0 = Minggu; null = setiap hari
            $table->time('jam_mulai')->nullable();
            $table->time('jam_selesai')->nullable(); // boleh melewati tengah malam
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();

            $table->string('tipe_penyesuaian', 20); // persen | potongan | harga_tetap
            $table->decimal('nilai', 12, 2);
            $table->unsignedInteger('prioritas')->default(0); // makin besar makin didahulukan

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->syncColumns();

            $table->index(['tenant_id', 'is_active', 'prioritas']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aturan_harga');
    }
};
