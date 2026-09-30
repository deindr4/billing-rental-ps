<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Antrean perubahan (diisi trigger). Lokal: menunggu dikirim ke cloud. Cloud: sumber tarikan server lokal.
        Schema::create('sync_antrean', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tabel', 50);
            $table->string('row_id', 36);
            $table->string('aksi', 10);            // upsert | hapus | pivot
            $table->uuid('tenant_id')->nullable();
            $table->uuid('sumber')->nullable();    // cloud: server lokal asal perubahan (null = diubah di cloud)
            $table->timestamp('created_at', 3)->nullable();

            $table->index(['tenant_id', 'id']);
            $table->index('created_at');
        });

        // Cloud: server lokal yang boleh sinkron (token per server)
        Schema::create('server_sinkron', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete(); // terikat saat sinkron pertama
            $table->string('nama', 100);
            $table->string('token_hash', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('terakhir_kontak')->nullable();
            $table->string('terakhir_ip', 45)->nullable();
            $table->string('catatan')->nullable();
            $table->timestamps();
        });

        // Riwayat proses sinkron (kedua sisi)
        Schema::create('sync_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('arah', 10);            // dorong | tarik | terima | kirim
            $table->string('status', 10);          // ok | gagal
            $table->unsignedInteger('jumlah')->default(0);
            $table->unsignedInteger('durasi_ms')->default(0);
            $table->uuid('server_id')->nullable();
            $table->string('pesan', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
        });

        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        DaftarTabel::lepasTrigger();

        Schema::dropIfExists('sync_log');
        Schema::dropIfExists('server_sinkron');
        Schema::dropIfExists('sync_antrean');
    }
};
