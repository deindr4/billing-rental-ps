<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('nama', 100);
            $table->timestamps();
            $table->syncColumns();

            $table->unique(['tenant_id', 'nama']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
