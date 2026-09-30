<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TV (Android TV / Google TV) yang menjalankan aplikasi TV Agent
        Schema::create('perangkat_tv', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('unit_id')->nullable()->constrained('units')->nullOnDelete();

            $table->string('android_id', 64);
            $table->string('merek', 50)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('versi_android', 20)->nullable();
            $table->string('versi_app', 20)->nullable();

            $table->string('status', 20)->default('aktif'); // aktif | dicabut
            $table->char('token_hash', 64)->nullable()->unique();
            $table->text('rahasia_offline')->nullable(); // terenkripsi, untuk kode darurat

            $table->timestamp('bypass_sampai')->nullable();
            $table->timestamp('terakhir_online')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('layar', 20)->nullable(); // tampilan terakhir yang dilaporkan TV

            $table->timestamp('dipasangkan_pada')->nullable();
            $table->foreignUuid('dipasangkan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->syncColumns();

            $table->index(['tenant_id', 'android_id']);
            $table->index(['cabang_id', 'status']);
            $table->index(['unit_id', 'status']);
        });

        // Kode pairing sementara: TV belum punya tenant, jadi tabel ini tidak ber-tenant
        Schema::create('pairing_tv', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('kode', 6)->unique();
            $table->char('kunci_hash', 64)->unique(); // kunci rahasia TV untuk menanyakan hasil pairing
            $table->string('android_id', 64);
            $table->json('info')->nullable();
            $table->foreignUuid('perangkat_id')->nullable()->constrained('perangkat_tv')->cascadeOnDelete();
            $table->text('kredensial')->nullable(); // token + rahasia terenkripsi, diambil TV sekali saja
            $table->timestamp('kedaluwarsa_pada');
            $table->timestamps();

            $table->index('kedaluwarsa_pada');
        });

        // Jejak aksi TV: pairing, bypass, perintah remote, pencabutan
        Schema::create('log_tv', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('perangkat_id')->constrained('perangkat_tv')->cascadeOnDelete();
            $table->foreignUuid('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('jenis', 30);
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['perangkat_id', 'created_at']);
            $table->index(['cabang_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_tv');
        Schema::dropIfExists('pairing_tv');
        Schema::dropIfExists('perangkat_tv');
    }
};
