<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('tipe_konsol_id')->constrained('tipe_konsol')->restrictOnDelete();
            $table->foreignUuid('kategori_unit_id')->nullable()->constrained('kategori_unit')->nullOnDelete();

            $table->string('kode', 20);   // TV1
            $table->string('nama', 100);  // TV 1 - PS5
            $table->string('lokasi', 100)->nullable(); // Lantai 1 - Wing Kiri
            $table->unsignedInteger('urutan')->default(0);

            // kosong | main | pause | menunggu_bayar | servis
            $table->string('status', 20)->default('kosong');

            // tv_agent | manual
            $table->string('mode_kontrol', 20)->default('manual');

            // tanpa_kontrol | android_tv | vidaa | tizen | smart_plug
            $table->string('tipe_perangkat', 20)->default('tanpa_kontrol');

            // null = ikut pengaturan cabang
            $table->string('posisi_timer', 20)->nullable(); // kiri_atas | kanan_atas | kiri_bawah | kanan_bawah
            $table->unsignedSmallInteger('durasi_bypass_menit')->nullable();

            $table->boolean('izinkan_booking')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->syncColumns();

            $table->unique(['cabang_id', 'kode']);
            $table->index(['cabang_id', 'status']);
            $table->index(['cabang_id', 'is_active', 'urutan']);
        });

        Schema::create('game_unit', function (Blueprint $table) {
            $table->foreignUuid('game_id')->constrained('games')->cascadeOnDelete();
            $table->foreignUuid('unit_id')->constrained('units')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['game_id', 'unit_id']);
            $table->index('unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_unit');
        Schema::dropIfExists('units');
    }
};
