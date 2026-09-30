<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rilis APK TV Agent. Satu aplikasi untuk semua tenant, dikelola super admin.
        Schema::create('rilis_apk', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('versi_nama', 20);               // 0.2.0 (versionName)
            $table->unsignedInteger('versi_kode')->unique(); // 2 (versionCode, harus naik)
            $table->string('file');                          // path di disk local (privat)
            $table->unsignedBigInteger('ukuran');
            $table->char('sha256', 64);
            $table->text('catatan')->nullable();
            $table->boolean('wajib')->default(false);        // TV harus update sebelum lanjut
            $table->boolean('aktif')->default(true);         // nonaktif = ditarik, tidak ditawarkan
            $table->foreignUuid('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['aktif', 'versi_kode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rilis_apk');
    }
};
