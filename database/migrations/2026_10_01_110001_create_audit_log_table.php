<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jejak aktivitas: perubahan data master & kejadian penting (login, PIN, batal, bypass, koreksi).
        Schema::create('audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('cabang_id')->nullable()->constrained('cabang')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('aksi', 40);            // dibuat | diubah | dihapus | login | pin_gagal | batal_transaksi | ...
            $table->boolean('anomali')->default(false); // tampil di "log anomali" laporan
            $table->string('subjek_type', 100)->nullable();
            $table->string('subjek_id', 36)->nullable();
            $table->string('keterangan', 255);
            $table->json('data')->nullable();      // {lama:{}, baru:{}} atau data kejadian

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['cabang_id', 'anomali', 'created_at']);
            $table->index(['subjek_type', 'subjek_id']);
            $table->index(['tenant_id', 'aksi']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_log_larangan_ubah');
            DB::unprepared(
                'CREATE TRIGGER audit_log_larangan_ubah BEFORE UPDATE ON audit_log FOR EACH ROW '
                ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log tidak bisa diubah.'"
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_log_larangan_ubah');
        }

        Schema::dropIfExists('audit_log');
    }
};
