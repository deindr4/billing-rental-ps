<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();

            $table->string('nomor', 40);
            $table->string('status', 10)->default('buka'); // buka | tutup
            $table->timestamp('dibuka_pada');
            $table->timestamp('ditutup_pada')->nullable();

            $table->unsignedBigInteger('kas_awal')->default(0);
            $table->bigInteger('kas_seharusnya')->nullable();
            $table->unsignedBigInteger('kas_fisik')->nullable();
            $table->bigInteger('selisih')->nullable();
            $table->json('rincian_pecahan')->nullable();
            $table->text('catatan_tutup')->nullable();
            $table->foreignUuid('ditutup_oleh')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();
            $table->syncColumns();

            $table->unique(['tenant_id', 'nomor']);
            $table->index(['cabang_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['cabang_id', 'dibuka_pada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
