<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pelanggan yang menunggu unit kosong di lounge (dipanggil lewat billboard + suara)
        Schema::create('antrean_lounge', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->date('tanggal');
            $table->unsignedSmallInteger('nomor');              // urut per cabang per hari
            $table->string('nama', 100);
            $table->string('telepon', 20)->nullable();
            $table->foreignUuid('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignUuid('tipe_konsol_id')->nullable()->constrained('tipe_konsol')->nullOnDelete(); // keinginan (PS4/PS5)
            $table->unsignedTinyInteger('jumlah_orang')->default(1);
            $table->foreignUuid('unit_id')->nullable()->constrained('units')->nullOnDelete(); // unit tujuan saat dipanggil
            $table->string('status', 15)->default('menunggu');   // menunggu | dipanggil | dilayani | batal
            $table->timestamp('dipanggil_pada')->nullable();
            $table->unsignedTinyInteger('jumlah_panggil')->default(0);
            $table->string('catatan')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->syncColumns();

            $table->index(['cabang_id', 'tanggal', 'status']);
        });

        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        Schema::dropIfExists('antrean_lounge');
        DaftarTabel::pasangTrigger();
    }
};
