<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kode', 20)->unique();
            $table->string('nama');
            $table->string('domain')->nullable()->unique();
            $table->string('status', 20)->default('aktif'); // aktif | suspend
            $table->unsignedInteger('max_cabang')->nullable();
            $table->unsignedInteger('max_unit')->nullable();
            $table->unsignedInteger('max_tv')->nullable();
            $table->unsignedInteger('max_user')->nullable();
            $table->date('langganan_berakhir')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
