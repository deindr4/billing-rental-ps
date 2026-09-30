<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengaturan', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->nullable()->constrained('cabang')->restrictOnDelete(); // null = berlaku untuk semua cabang
            $table->string('kunci', 100);
            $table->json('nilai')->nullable();
            $table->timestamps();
            $table->syncColumns();

            $table->index(['tenant_id', 'kunci']);
            $table->index(['tenant_id', 'cabang_id', 'kunci']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan');
    }
};
