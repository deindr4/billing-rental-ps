<?php

use App\Services\Sinkron\SinkronService;
use App\Support\Sinkron\DaftarTabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optimasi tabel yang tumbuh besar:
 * - sync_antrean (tabel,row_id,aksi): merapikan antrean ganda dengan cepat
 * - sesi (cabang_id,mulai_pada): laporan & Analisa per periode
 * - audit_log (cabang_id,aksi,created_at): Analisa audit kasir per cabang
 * - transaksi_item (transaksi_id,jenis): laporan pendapatan per jenis
 * Lalu antrean sinkron ganda dirapikan & trigger dipasang ulang (heartbeat TV tidak lagi masuk antrean).
 */
return new class extends Migration
{
    private const INDEX = [
        'sync_antrean' => [['tabel', 'row_id', 'aksi'], 'sync_antrean_tabel_row_aksi_index'],
        'sesi' => [['cabang_id', 'mulai_pada'], 'sesi_cabang_mulai_index'],
        'audit_log' => [['cabang_id', 'aksi', 'created_at'], 'audit_log_cabang_aksi_waktu_index'],
        'transaksi_item' => [['transaksi_id', 'jenis'], 'transaksi_item_transaksi_jenis_index'],
    ];

    public function up(): void
    {
        foreach (self::INDEX as $tabel => [$kolom, $nama]) {
            if (Schema::hasTable($tabel) && ! Schema::hasIndex($tabel, $nama)) {
                Schema::table($tabel, fn (Blueprint $t) => $t->index($kolom, $nama));
            }
        }

        app(SinkronService::class)->rapikanAntrean();
        DaftarTabel::pasangTrigger();
    }

    public function down(): void
    {
        foreach (self::INDEX as $tabel => [, $nama]) {
            if (Schema::hasTable($tabel) && Schema::hasIndex($tabel, $nama)) {
                Schema::table($tabel, fn (Blueprint $t) => $t->dropIndex($nama));
            }
        }
    }
};
