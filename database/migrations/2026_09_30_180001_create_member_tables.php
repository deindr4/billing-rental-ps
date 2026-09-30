<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Member tingkat tenant: bisa dipakai di semua cabang
        Schema::create('members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->nullable()->constrained('cabang')->nullOnDelete(); // cabang daftar

            $table->string('kode', 20);
            $table->string('nama', 100);
            $table->string('telepon', 20);
            $table->string('email', 150)->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->text('catatan')->nullable();

            // Saldo & manfaat (nilai terkini; riwayat lengkap di member_mutasi)
            $table->bigInteger('saldo')->default(0);
            $table->integer('poin')->default(0);
            $table->integer('stamp')->default(0);
            $table->unsignedBigInteger('total_belanja')->default(0);
            $table->unsignedInteger('jumlah_kunjungan')->default(0);
            $table->string('tier', 30)->default('Reguler');
            $table->timestamp('terakhir_kunjungan')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->syncColumns();

            $table->unique(['tenant_id', 'kode']);
            $table->unique(['tenant_id', 'telepon']);
            $table->index(['tenant_id', 'nama']);
        });

        // Buku besar member: saldo, poin, stamp, belanja. Tidak pernah dihapus/diubah.
        Schema::create('member_mutasi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->nullable()->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('member_id')->constrained('members')->restrictOnDelete();

            $table->string('akun', 10);   // saldo | poin | stamp | belanja
            $table->string('jenis', 20);  // topup | bonus | bayar | refund | dapat | tukar | batal | koreksi
            $table->bigInteger('jumlah'); // + masuk, - keluar
            $table->bigInteger('saldo_akhir');

            $table->foreignUuid('transaksi_id')->nullable()->constrained('transaksi')->restrictOnDelete();
            $table->foreignUuid('pembayaran_id')->nullable()->constrained('pembayaran')->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('keterangan', 255)->nullable();
            $table->timestamps();
            $table->syncColumns();

            $table->index(['member_id', 'akun', 'created_at']);
            $table->index(['transaksi_id', 'akun']);
        });

        Schema::table('transaksi', function (Blueprint $table) {
            $table->foreign('member_id')->references('id')->on('members')->restrictOnDelete();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS member_mutasi_larangan_hapus');
            DB::unprepared(
                'CREATE TRIGGER member_mutasi_larangan_hapus BEFORE DELETE ON member_mutasi FOR EACH ROW '
                ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data member_mutasi tidak bisa dihapus. Gunakan koreksi.'"
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS member_mutasi_larangan_hapus');
        }

        Schema::table('transaksi', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
        });
        Schema::dropIfExists('member_mutasi');
        Schema::dropIfExists('members');
    }
};
