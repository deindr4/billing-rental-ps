<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counter nomor transaksi per cabang, prefix, sumber (L/V), dan tanggal.
 * Tidak disinkronkan: tiap server punya kode sumber sendiri sehingga tidak bentrok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nomor_urut', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->string('prefix', 10);
            $table->char('sumber', 1); // L = lokal, V = VPS
            $table->date('tanggal');
            $table->unsignedInteger('terakhir')->default(0);
            $table->timestamps();

            $table->unique(['cabang_id', 'prefix', 'sumber', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nomor_urut');
    }
};
