<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sewa PlayStation bawa pulang (Playbox):
 * - playbox: inventaris terpisah dari unit rental (kode, nomor seri, kelengkapan + harga ganti, tarif jam/hari/minggu/
 *   bulan, denda telat)
 * - penyewa: identitas (NIK terenkripsi), alamat rumah/kost + koordinat (Google Maps tanpa API), foto & KTP (disk
 *   privat), daftar hitam
 * - sewa_playbox: satu sewa (bayar di muka) — jaminan, deposit, checklist & foto keluar/kembali, tanda tangan,
 *   perpanjangan, denda, kerusakan
 * Waktu memakai DATETIME (bukan TIMESTAMP) — lihat 2026_10_04_090001.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('playbox')) {
            Schema::create('playbox', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
                $table->string('kode', 20);
                $table->string('nama', 80);
                $table->string('nomor_seri', 60)->nullable();
                $table->json('kelengkapan')->nullable();             // [{nama, jumlah, harga_ganti}]
                $table->unsignedBigInteger('harga_jam')->default(0);   // 0 = tidak disewakan per jam
                $table->unsignedBigInteger('harga_hari')->default(0);
                $table->unsignedBigInteger('harga_minggu')->default(0);
                $table->unsignedBigInteger('harga_bulan')->default(0);
                $table->unsignedBigInteger('denda_jam')->default(0);   // denda telat per jam
                $table->unsignedBigInteger('denda_hari')->default(0);  // denda telat per hari (dipakai bila denda_jam 0)
                $table->unsignedBigInteger('deposit_saran')->default(0);
                $table->string('status', 15)->default('tersedia');    // tersedia | disewa | servis
                $table->text('catatan')->nullable();
                $table->unsignedInteger('urutan')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->syncColumns();
            });
        }

        if (! Schema::hasTable('penyewa')) {
            Schema::create('penyewa', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->uuid('member_id')->nullable();
                $table->string('nama', 100);
                $table->string('telepon', 30);
                $table->text('nik')->nullable();                     // terenkripsi
                $table->text('alamat')->nullable();
                $table->string('jenis_tempat', 10)->default('rumah'); // rumah | kost
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->string('foto')->nullable();                  // disk privat
                $table->string('foto_ktp')->nullable();              // disk privat
                $table->text('catatan')->nullable();
                $table->boolean('daftar_hitam')->default(false);
                $table->text('alasan_daftar_hitam')->nullable();
                $table->timestamps();
                $table->syncColumns();

                $table->index(['tenant_id', 'telepon']);
            });
        }

        if (! Schema::hasTable('sewa_playbox')) {
            Schema::create('sewa_playbox', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->foreignUuid('cabang_id')->constrained('cabang')->restrictOnDelete();
                $table->string('nomor', 40);
                $table->foreignUuid('playbox_id')->constrained('playbox')->restrictOnDelete();
                $table->foreignUuid('penyewa_id')->constrained('penyewa')->restrictOnDelete();
                $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
                $table->foreignUuid('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
                $table->foreignUuid('transaksi_id')->nullable()->constrained('transaksi')->nullOnDelete();
                $table->foreignUuid('transaksi_kembali_id')->nullable()->constrained('transaksi')->nullOnDelete();
                $table->string('status', 15)->default('berjalan'); // berjalan | selesai | batal

                $table->string('satuan', 10);                      // jam | hari | minggu | bulan
                $table->unsignedInteger('jumlah');
                $table->unsignedBigInteger('harga_satuan');
                $table->dateTime('mulai_pada');
                $table->dateTime('jatuh_tempo');
                $table->dateTime('kembali_pada')->nullable();

                $table->text('alamat')->nullable();                // tujuan saat sewa (salinan)
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();

                $table->json('jaminan')->nullable();               // [{jenis, keterangan, nomor, foto}]
                $table->unsignedBigInteger('deposit')->default(0);
                $table->unsignedBigInteger('deposit_dipotong')->default(0);
                $table->json('checklist_keluar')->nullable();      // [{nama, jumlah, kondisi, harga_ganti}]
                $table->json('checklist_kembali')->nullable();     // [{nama, jumlah, kondisi, biaya}]
                $table->json('foto_keluar')->nullable();           // [path] disk privat
                $table->json('foto_kembali')->nullable();
                $table->string('tanda_tangan')->nullable();        // PNG disk privat
                $table->json('perpanjangan')->nullable();          // [{satuan, jumlah, harga, dari, ke, transaksi_id, oleh, pada}]
                $table->unsignedBigInteger('denda')->default(0);
                $table->unsignedBigInteger('biaya_kerusakan')->default(0);
                $table->dateTime('diingatkan_pada')->nullable();   // pengingat WA jatuh tempo
                $table->text('catatan')->nullable();
                $table->timestamps();
                $table->syncColumns();

                $table->index(['status', 'jatuh_tempo']);
            });
        }

        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        Schema::dropIfExists('sewa_playbox');
        Schema::dropIfExists('penyewa');
        Schema::dropIfExists('playbox');
        DaftarTabel::pasangTrigger();
    }
};
