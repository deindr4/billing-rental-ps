<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kategori_unit', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('kode', 20); // REG, VIP
            $table->string('nama', 50);
            $table->unsignedInteger('urutan')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->syncColumns();

            $table->unique(['tenant_id', 'kode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kategori_unit');
    }
};
