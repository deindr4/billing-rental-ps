<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifikasi_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->nullable()->constrained('cabang')->restrictOnDelete();

            $table->string('saluran', 20);  // telegram | whatsapp
            $table->string('jenis', 40);    // tutup_kas | tes | ...
            $table->string('tujuan', 150)->nullable();
            $table->string('status', 20)->default('antre'); // antre | terkirim | gagal
            $table->unsignedSmallInteger('percobaan')->default(0);
            $table->text('pesan_error')->nullable();
            $table->nullableUuidMorphs('referensi');
            $table->timestamp('dikirim_pada')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['cabang_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifikasi_log');
    }
};
