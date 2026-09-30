<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Booking unit (online dari portal publik atau dicatat kasir)
        Schema::create('booking', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignUuid('member_id')->nullable()->constrained('members')->nullOnDelete();

            $table->string('kode', 12)->unique();                 // BK7F3Q9 (ditunjukkan pelanggan ke kasir)
            $table->string('nama', 100);
            $table->string('telepon', 20);
            $table->dateTime('mulai_pada');
            $table->dateTime('selesai_pada');
            $table->unsignedSmallInteger('durasi_menit');
            $table->unsignedBigInteger('perkiraan_harga')->default(0);
            $table->string('catatan', 255)->nullable();

            $table->string('status', 15)->default('menunggu');  // menunggu | dikonfirmasi | checkin | batal | tidak_datang
            $table->string('sumber', 10)->default('online');     // online | kasir
            $table->foreignUuid('sesi_id')->nullable()->constrained('sesi')->nullOnDelete();
            $table->string('alasan_batal', 255)->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->syncColumns();

            $table->index(['cabang_id', 'mulai_pada', 'status']);
            $table->index(['unit_id', 'mulai_pada']);
            $table->index(['telepon']);
        });

        Schema::create('turnamen', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->string('slug', 80)->unique();
            $table->string('nama', 120);
            $table->string('game', 100);
            $table->dateTime('mulai_pada');
            $table->unsignedBigInteger('biaya_daftar')->default(0);
            $table->unsignedSmallInteger('kuota')->default(16);
            $table->text('hadiah')->nullable();
            $table->text('aturan')->nullable();
            $table->boolean('daftar_online')->default(true);
            $table->string('status', 15)->default('pendaftaran'); // draft | pendaftaran | berjalan | selesai | batal
            $table->timestamps();
            $table->syncColumns();

            $table->index(['cabang_id', 'status', 'mulai_pada']);
        });

        Schema::create('turnamen_peserta', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('turnamen_id')->constrained('turnamen')->cascadeOnDelete();
            $table->foreignUuid('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('nama', 100);                         // nama tim / gamer tag
            $table->string('telepon', 20);
            $table->string('status', 15)->default('terdaftar');  // terdaftar | lunas | batal
            $table->foreignUuid('transaksi_id')->nullable()->constrained('transaksi')->nullOnDelete();
            $table->unsignedSmallInteger('unggulan')->nullable();
            $table->string('sumber', 10)->default('kasir');       // online | kasir
            $table->timestamps();
            $table->syncColumns();

            $table->index(['turnamen_id', 'status']);
        });

        // Bagan gugur: babak 1 = babak pertama, nomor = urutan dalam babak
        Schema::create('turnamen_pertandingan', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('turnamen_id')->constrained('turnamen')->cascadeOnDelete();
            $table->unsignedTinyInteger('babak');
            $table->unsignedSmallInteger('nomor');
            $table->foreignUuid('peserta_a_id')->nullable()->constrained('turnamen_peserta')->nullOnDelete();
            $table->foreignUuid('peserta_b_id')->nullable()->constrained('turnamen_peserta')->nullOnDelete();
            $table->unsignedSmallInteger('skor_a')->nullable();
            $table->unsignedSmallInteger('skor_b')->nullable();
            $table->foreignUuid('pemenang_id')->nullable()->constrained('turnamen_peserta')->nullOnDelete();
            $table->foreignUuid('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('status', 10)->default('menunggu');   // menunggu | main | selesai
            $table->timestamps();
            $table->syncColumns();

            $table->unique(['turnamen_id', 'babak', 'nomor']);
        });

        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        Schema::dropIfExists('turnamen_pertandingan');
        Schema::dropIfExists('turnamen_peserta');
        Schema::dropIfExists('turnamen');
        Schema::dropIfExists('booking');
        DaftarTabel::pasangTrigger();
    }
};
