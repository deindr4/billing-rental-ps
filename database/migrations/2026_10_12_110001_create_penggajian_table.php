<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekap gaji per karyawan per periode: pokok (prorata) + upah per hari hadir + upah per jam kerja + bonus target
 * omzet shift + penyesuaian − potongan selisih kas yang DISETUJUI owner. Status: draft → disetujui → dibayar
 * (dicatat sebagai pengeluaran kategori gaji) / batal. Rincian lengkap disimpan di JSON `rincian`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('penggajian')) {
            return;
        }

        Schema::create('penggajian', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('karyawan_id')->constrained('karyawan')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->nullable()->constrained('cabang')->nullOnDelete(); // cabang pembayar
            $table->string('nomor', 40);
            $table->date('periode_mulai');
            $table->date('periode_selesai');
            $table->string('status', 15)->default('draft'); // draft | disetujui | dibayar | batal

            $table->bigInteger('gaji_pokok')->default(0);
            $table->bigInteger('upah_hadir')->default(0);
            $table->bigInteger('upah_jam')->default(0);
            $table->bigInteger('bonus')->default(0);
            $table->bigInteger('penyesuaian')->default(0);
            $table->bigInteger('potongan')->default(0);
            $table->bigInteger('total')->default(0);
            $table->json('rincian')->nullable();
            $table->text('catatan')->nullable();

            $table->foreignUuid('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('disetujui_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('disetujui_pada')->nullable();
            $table->dateTime('dibayar_pada')->nullable();
            $table->string('sumber_dana', 20)->nullable();
            $table->foreignUuid('pengeluaran_id')->nullable()->constrained('pengeluaran')->nullOnDelete();

            $table->timestamps();
            $table->syncColumns();

            $table->index(['karyawan_id', 'periode_mulai']);
        });

        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        Schema::dropIfExists('penggajian');
        DaftarTabel::pasangTrigger();
    }
};
