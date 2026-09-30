<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paket_harga', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->nullable()->constrained('cabang')->restrictOnDelete(); // null = semua cabang
            $table->foreignUuid('tipe_konsol_id')->nullable()->constrained('tipe_konsol')->restrictOnDelete(); // null = semua tipe
            $table->foreignUuid('kategori_unit_id')->nullable()->constrained('kategori_unit')->restrictOnDelete(); // null = semua kategori

            $table->string('nama', 100);
            $table->string('jenis', 20); // per_jam | paket
            $table->unsignedInteger('durasi_menit')->nullable(); // wajib untuk jenis paket
            $table->unsignedBigInteger('harga'); // Rupiah, tanpa desimal
            $table->string('keterangan')->nullable();
            $table->unsignedInteger('urutan')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->syncColumns();

            $table->index(['tenant_id', 'is_active', 'urutan']);
            $table->index(['tenant_id', 'tipe_konsol_id']);
            $table->index(['tenant_id', 'cabang_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paket_harga');
    }
};
