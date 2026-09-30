<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inventaris & aset (konsol, TV, stik, AC, furnitur...). Penyusutan garis lurus.
        Schema::create('aset', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('unit_id')->nullable()->constrained('units')->nullOnDelete();

            $table->string('kategori', 20);   // konsol | tv | stik | ac | furnitur | elektronik | lainnya
            $table->string('nama', 120);
            $table->string('merek', 60)->nullable();
            $table->string('serial', 80)->nullable();

            $table->date('tanggal_beli');
            $table->unsignedBigInteger('harga_perolehan');
            $table->unsignedBigInteger('nilai_sisa')->default(0);
            $table->unsignedSmallInteger('umur_bulan');   // umur ekonomis
            $table->date('garansi_sampai')->nullable();

            $table->string('status', 20)->default('aktif'); // aktif | servis | rusak | dilepas
            $table->date('dilepas_pada')->nullable();
            $table->unsignedBigInteger('nilai_lepas')->nullable(); // hasil jual saat dilepas

            $table->unsignedSmallInteger('interval_servis_hari')->nullable();
            $table->date('servis_terakhir')->nullable();

            $table->string('foto')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();
            $table->syncColumns();

            $table->index(['cabang_id', 'status']);
            $table->index(['cabang_id', 'kategori']);
        });

        // Tiket maintenance / servis (per aset dan/atau unit)
        Schema::create('maintenance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('aset_id')->nullable()->constrained('aset')->nullOnDelete();
            $table->foreignUuid('unit_id')->nullable()->constrained('units')->nullOnDelete();

            $table->string('jenis', 20);   // servis_rutin | perbaikan | pembersihan | ganti_part | upgrade
            $table->string('judul', 150);
            $table->text('deskripsi')->nullable();
            $table->string('status', 20)->default('dijadwalkan'); // dijadwalkan | dikerjakan | selesai | batal

            $table->date('dijadwalkan_pada')->nullable();
            $table->timestamp('mulai_pada')->nullable();
            $table->timestamp('selesai_pada')->nullable();

            $table->unsignedBigInteger('biaya')->default(0);
            $table->string('vendor', 100)->nullable();
            $table->text('hasil')->nullable();
            $table->foreignUuid('pengeluaran_id')->nullable()->constrained('pengeluaran')->nullOnDelete();

            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('diselesaikan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->syncColumns();

            $table->index(['cabang_id', 'status']);
            $table->index(['unit_id', 'status']);
            $table->index(['aset_id', 'created_at']);
        });

        // Arus modal owner: suntikan modal & prive (penarikan laba)
        Schema::create('modal_mutasi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->string('jenis', 10);      // modal | prive
            $table->unsignedBigInteger('jumlah');
            $table->date('tanggal');
            $table->string('sumber', 20);     // rekening | kas_laci
            $table->string('keterangan', 255);
            $table->foreignUuid('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 15)->default('aktif'); // aktif | dibatalkan
            $table->timestamps();
            $table->syncColumns();

            $table->index(['cabang_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modal_mutasi');
        Schema::dropIfExists('maintenance');
        Schema::dropIfExists('aset');
    }
};
