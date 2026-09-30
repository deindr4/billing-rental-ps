<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Iklan bergambar di billboard publik, tayang dalam periode tertentu lalu terhapus otomatis.
        // Gambar (WebP) disimpan di database supaya ikut tersinkron ke server cloud.
        Schema::create('iklan', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->nullable()->constrained('cabang')->nullOnDelete(); // null = semua cabang
            $table->string('judul', 120);
            $table->string('pengiklan', 120)->nullable();
            $table->string('tautan', 300)->nullable();
            $table->mediumText('gambar');                 // base64 WebP
            $table->unsignedSmallInteger('lebar')->default(0);
            $table->unsignedSmallInteger('tinggi')->default(0);
            $table->dateTime('mulai_pada');
            $table->dateTime('selesai_pada');
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('dilihat')->default(0);
            $table->timestamps();
            $table->syncColumns();

            $table->index(['tenant_id', 'is_active', 'mulai_pada', 'selesai_pada']);
        });

        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        Schema::dropIfExists('iklan');
        DaftarTabel::pasangTrigger();
    }
};
