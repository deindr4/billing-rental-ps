<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengeluaran', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('disetujui_oleh')->nullable()->constrained('users')->restrictOnDelete();

            $table->string('nomor', 40);
            $table->string('kategori', 30);        // fnb | operasional | sparepart | gaji | utilitas | lainnya
            $table->unsignedBigInteger('jumlah');
            $table->string('keterangan', 255);
            $table->string('sumber_dana', 20);     // kas_laci | rekening
            $table->string('foto_nota')->nullable();

            $table->string('status', 20)->default('aktif'); // aktif | dibatalkan
            $table->timestamp('dibatalkan_pada')->nullable();
            $table->foreignUuid('dibatalkan_oleh')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('alasan_batal')->nullable();

            $table->timestamps();
            $table->syncColumns();

            $table->unique(['tenant_id', 'nomor']);
            $table->index(['cabang_id', 'created_at']);
            $table->index(['cabang_id', 'kategori', 'created_at']);
            $table->index(['shift_id', 'sumber_dana', 'status']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS pengeluaran_larangan_hapus');
            DB::unprepared(
                "CREATE TRIGGER pengeluaran_larangan_hapus BEFORE DELETE ON pengeluaran FOR EACH ROW "
                ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data pengeluaran tidak bisa dihapus. Gunakan pembatalan.'"
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS pengeluaran_larangan_hapus');
        }

        Schema::dropIfExists('pengeluaran');
    }
};
