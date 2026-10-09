<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data karyawan (boleh tanpa akun login, mis. OB). NIK & nomor rekening disimpan terenkripsi.
 * Kolom gaji disiapkan untuk rekap gaji (tahap D): pokok bulanan / upah per shift / per jam + bonus target omzet,
 * periode gaji dipilih per karyawan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('karyawan')) {
            return;
        }

        Schema::create('karyawan', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->nullable()->constrained('cabang')->nullOnDelete(); // cabang utama
            $table->foreignUuid('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();

            $table->string('nama', 100);
            $table->string('jabatan', 50)->nullable();
            $table->text('nik')->nullable();                 // terenkripsi
            $table->string('telepon', 30)->nullable();
            $table->text('alamat')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->date('tanggal_masuk')->nullable();
            $table->date('tanggal_keluar')->nullable();
            $table->string('kontak_darurat_nama', 100)->nullable();
            $table->string('kontak_darurat_telepon', 30)->nullable();
            $table->string('bank', 50)->nullable();
            $table->text('no_rekening')->nullable();         // terenkripsi
            $table->string('atas_nama', 100)->nullable();
            $table->string('foto')->nullable();
            $table->string('pin')->nullable();               // absen bila tanpa akun login (hash)
            $table->text('catatan')->nullable();
            $table->boolean('is_active')->default(true);

            // Gaji & bonus (dipakai rekap gaji)
            $table->string('periode_gaji', 10)->default('bulanan'); // bulanan | mingguan | harian
            $table->unsignedBigInteger('gaji_pokok')->default(0);   // per bulan
            $table->unsignedBigInteger('upah_shift')->default(0);   // per shift / hari hadir
            $table->unsignedBigInteger('upah_jam')->default(0);     // per jam kerja (absensi)
            $table->unsignedBigInteger('bonus_target')->default(0); // target omzet per shift (0 = tanpa bonus)
            $table->string('bonus_jenis', 10)->default('nominal');  // nominal | persen (dari kelebihan target)
            $table->unsignedBigInteger('bonus_nilai')->default(0);

            $table->timestamps();
            $table->syncColumns();
        });

        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        Schema::dropIfExists('karyawan');
        DaftarTabel::pasangTrigger();
    }
};
