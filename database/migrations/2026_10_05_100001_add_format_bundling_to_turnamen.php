<?php

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turnamen: pilihan format (gugur, gugur ganda, liga, fase grup + gugur), bundling F&B gratis saat daftar,
 * dan total hadiah (untuk rincian keuangan).
 * Aman dijalankan ulang (DDL MariaDB tidak transaksional: percobaan yang gagal di tengah tidak dibatalkan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turnamen', function (Blueprint $table) {
            if (! Schema::hasColumn('turnamen', 'format')) {
                // gugur | gugur_ganda | liga | grup_gugur
                $table->string('format', 15)->default('gugur')->after('game');
                $table->unsignedTinyInteger('jumlah_grup')->nullable()->after('format');
                $table->unsignedTinyInteger('lolos_per_grup')->nullable()->after('jumlah_grup');
                $table->unsignedTinyInteger('putaran')->default(1)->after('lolos_per_grup'); // 1 / 2 (pulang-pergi)
                $table->foreignUuid('bonus_produk_id')->nullable()->after('biaya_daftar')->constrained('produk')->nullOnDelete();
                $table->unsignedSmallInteger('bonus_qty')->default(0)->after('bonus_produk_id');
                $table->unsignedBigInteger('total_hadiah')->default(0)->after('hadiah');
            }
        });

        if (! Schema::hasColumn('turnamen_peserta', 'grup')) {
            Schema::table('turnamen_peserta', fn (Blueprint $table) => $table->string('grup', 2)->nullable()->after('unggulan'));
        }

        if (! Schema::hasColumn('turnamen_pertandingan', 'tahap')) {
            Schema::table('turnamen_pertandingan', function (Blueprint $table) {
                // gugur | atas | bawah | final (gugur ganda) | liga | grup
                $table->string('tahap', 8)->default('gugur')->after('turnamen_id');
                $table->string('grup', 2)->nullable()->after('tahap');
            });
        }

        $index = collect(Schema::getIndexes('turnamen_pertandingan'))->pluck('name');

        // Index baru dulu: foreign key turnamen_id memakai index yang diawali turnamen_id
        if (! $index->contains('turnamen_pertandingan_turnamen_id_tahap_babak_nomor_unique')) {
            Schema::table('turnamen_pertandingan', fn (Blueprint $table) => $table->unique(['turnamen_id', 'tahap', 'babak', 'nomor']));
        }

        if ($index->contains('turnamen_pertandingan_turnamen_id_babak_nomor_unique')) {
            Schema::table('turnamen_pertandingan', fn (Blueprint $table) => $table->dropUnique(['turnamen_id', 'babak', 'nomor']));
        }

        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        Schema::table('turnamen_pertandingan', function (Blueprint $table) {
            $table->unique(['turnamen_id', 'babak', 'nomor']);
            $table->dropUnique(['turnamen_id', 'tahap', 'babak', 'nomor']);
            $table->dropColumn(['tahap', 'grup']);
        });

        Schema::table('turnamen_peserta', fn (Blueprint $table) => $table->dropColumn('grup'));

        Schema::table('turnamen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bonus_produk_id');
            $table->dropColumn(['format', 'jumlah_grup', 'lolos_per_grup', 'putaran', 'bonus_qty', 'total_hadiah']);
        });

        DaftarTabel::pasangTrigger();
    }
};
