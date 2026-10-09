<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal & absensi karyawan:
 * - template_shift: jam kerja (Pagi 10:00–17:00, Malam 17:00–02:00 lewat tengah malam) + toleransi terlambat
 * - jadwal_karyawan: jadwal mingguan berulang (karyawan × hari → template, tanpa baris = libur)
 * - absensi: masuk/pulang dengan PIN + foto selfie; terlambat & menit kerja dihitung dari jadwal
 * Waktu memakai DATETIME (bukan TIMESTAMP) — lihat 2026_10_04_090001.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('template_shift')) {
            Schema::create('template_shift', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->string('nama', 40);
                $table->time('jam_mulai');
                $table->time('jam_selesai');
                $table->unsignedSmallInteger('toleransi_menit')->default(10);
                $table->unsignedInteger('urutan')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->syncColumns();
            });
        }

        if (! Schema::hasTable('jadwal_karyawan')) {
            Schema::create('jadwal_karyawan', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->foreignUuid('karyawan_id')->constrained('karyawan')->cascadeOnDelete();
                $table->unsignedTinyInteger('hari'); // 0 = Minggu … 6 = Sabtu (Carbon dayOfWeek)
                $table->foreignUuid('template_shift_id')->constrained('template_shift')->restrictOnDelete();
                $table->timestamps();
                $table->syncColumns();

                $table->unique(['karyawan_id', 'hari']);
            });
        }

        if (! Schema::hasTable('absensi')) {
            Schema::create('absensi', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
                $table->foreignUuid('karyawan_id')->constrained('karyawan')->restrictOnDelete();
                $table->date('tanggal');
                $table->dateTime('masuk_pada');
                $table->dateTime('pulang_pada')->nullable();
                $table->string('foto_masuk')->nullable();
                $table->string('foto_pulang')->nullable();
                $table->foreignUuid('template_shift_id')->nullable()->constrained('template_shift')->nullOnDelete();
                $table->dateTime('jadwal_mulai')->nullable();
                $table->dateTime('jadwal_selesai')->nullable();
                $table->unsignedInteger('terlambat_menit')->default(0);
                $table->unsignedInteger('pulang_cepat_menit')->default(0);
                $table->unsignedInteger('menit_kerja')->nullable();
                $table->text('catatan')->nullable();
                $table->timestamps();
                $table->syncColumns();

                $table->index(['karyawan_id', 'tanggal']);
            });
        }

        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        Schema::dropIfExists('absensi');
        Schema::dropIfExists('jadwal_karyawan');
        Schema::dropIfExists('template_shift');
        DaftarTabel::pasangTrigger();
    }
};
