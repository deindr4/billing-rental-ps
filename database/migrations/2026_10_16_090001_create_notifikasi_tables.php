<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lonceng notifikasi (pojok kanan atas aplikasi kasir & admin):
 * - notifikasi        : kejadian penting (Playbox, pembatalan, booking, kas, stok, keamanan, sistem). Hanya ditambah,
 *                       ikut tersinkron lokal <-> cloud. `kunci` mencegah notifikasi ganda (mis. telat per sewa per hari).
 * - notifikasi_baca   : yang sudah dibaca per pengguna (per server, tidak disinkron)
 * - notifikasi_pengguna: batas "tandai semua dibaca" per pengguna
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifikasi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('cabang_id')->nullable();
            $table->string('jenis', 40);
            $table->string('kelompok', 20);
            $table->string('tingkat', 12); // penting | peringatan | info
            $table->string('judul', 150);
            $table->string('isi', 500)->nullable();
            $table->string('url', 300)->nullable();
            $table->string('kunci', 150)->nullable();
            $table->nullableUuidMorphs('subjek');
            $table->uuid('user_id')->nullable(); // pelaku (tidak diberi tahu kejadiannya sendiri)
            $table->timestamps();
            $table->syncColumns();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['cabang_id', 'created_at']);
            $table->unique(['tenant_id', 'kunci']);
        });

        Schema::create('notifikasi_baca', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->foreignUuid('notifikasi_id')->constrained('notifikasi')->cascadeOnDelete();
            $table->timestamp('dibaca_pada')->useCurrent();
            $table->primary(['user_id', 'notifikasi_id']);
        });

        Schema::create('notifikasi_pengguna', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->timestamp('dibaca_sampai')->nullable();
        });

        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        Schema::dropIfExists('notifikasi_pengguna');
        Schema::dropIfExists('notifikasi_baca');
        Schema::dropIfExists('notifikasi');
        DaftarTabel::pasangTrigger();
    }
};
