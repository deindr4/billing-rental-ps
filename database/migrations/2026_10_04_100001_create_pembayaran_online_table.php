<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bayar mandiri lewat payment gateway (QRIS di TV): mulai main / isi ulang waktu tanpa kasir
        Schema::create('pembayaran_online', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignUuid('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignUuid('sesi_id')->nullable()->constrained('sesi')->nullOnDelete();
            $table->foreignUuid('transaksi_id')->nullable()->constrained('transaksi')->nullOnDelete();

            $table->string('provider', 15);                 // simulasi | tripay | winpay
            $table->string('jenis', 10);                    // mulai | isi_ulang
            $table->string('merchant_ref', 40)->unique();   // nomor kita (BYR-...)
            $table->string('referensi', 80)->nullable()->unique(); // nomor dari gateway
            $table->unsignedBigInteger('nominal');          // dibayar pelanggan
            $table->unsignedBigInteger('biaya')->default(0); // potongan gateway (ditanggung rental)
            $table->unsignedSmallInteger('menit');           // waktu yang didapat
            $table->foreignUuid('paket_harga_id')->nullable()->constrained('paket_harga')->nullOnDelete();
            $table->text('qr_string')->nullable();
            $table->dateTime('kedaluwarsa_pada');

            // menunggu | dibayar (belum diterapkan) | selesai (sesi jalan) | kedaluwarsa | gagal | perlu_tindakan
            $table->string('status', 15)->default('menunggu');
            $table->dateTime('dibayar_pada')->nullable();
            $table->dateTime('diproses_pada')->nullable();
            $table->string('catatan', 255)->nullable();
            $table->json('data')->nullable();               // respons mentah gateway (untuk telusur)
            $table->timestamps();
            $table->syncColumns();

            $table->index(['unit_id', 'status']);
            $table->index(['cabang_id', 'status', 'created_at']);
        });

        // Kode acak per unit untuk QR "Scan untuk main" di TV (supaya alamat tidak bisa ditebak)
        Schema::table('units', function (Blueprint $table) {
            $table->string('token_bayar', 24)->nullable()->unique()->after('wallpaper');
        });

        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('token_bayar');
        });
        Schema::dropIfExists('pembayaran_online');
        DaftarTabel::pasangTrigger();
    }
};
