<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Perbaikan: di MariaDB/MySQL dengan explicit_defaults_for_timestamp=OFF, kolom TIMESTAMP NOT NULL pertama
 * di sebuah tabel otomatis mendapat "ON UPDATE CURRENT_TIMESTAMP" — nilainya ter-reset ke waktu sekarang
 * setiap baris diperbarui (jam mulai sesi, jam buka kas, waktu bayar, masa kode pairing).
 * Diubah jadi DATETIME biasa, lalu nilai yang sudah tergeser dipulihkan dari created_at.
 */
return new class extends Migration
{
    private array $kolom = [
        ['sesi', 'mulai_pada'],
        ['shifts', 'dibuka_pada'],
        ['pembayaran', 'dibayar_pada'],
        ['pairing_tv', 'kedaluwarsa_pada'],
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($this->kolom as [$tabel, $kolom]) {
            DB::statement("ALTER TABLE `{$tabel}` MODIFY `{$kolom}` DATETIME NOT NULL");
        }

        // Pulihkan nilai yang ikut berubah saat baris diperbarui
        DB::statement('UPDATE shifts SET dibuka_pada = created_at WHERE created_at IS NOT NULL AND dibuka_pada > created_at');
        DB::statement('UPDATE pembayaran SET dibayar_pada = created_at WHERE created_at IS NOT NULL AND dibayar_pada > created_at');

        // Jam mulai sesi = dibuat + waktu pilih game (tercatat di sesi_log "mulai")
        DB::statement(<<<'SQL'
            UPDATE sesi s
            LEFT JOIN sesi_log l ON l.sesi_id = s.id AND l.jenis = 'mulai'
            SET s.mulai_pada = DATE_ADD(s.created_at, INTERVAL COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(l.data, '$.pilih_game_menit')) AS UNSIGNED), 0) MINUTE)
            WHERE s.created_at IS NOT NULL
              AND s.mulai_pada > DATE_ADD(s.created_at, INTERVAL COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(l.data, '$.pilih_game_menit')) AS UNSIGNED), 0) MINUTE)
              AND NOT EXISTS (SELECT 1 FROM sesi_log m WHERE m.sesi_id = s.id AND m.jenis = 'mulai_sekarang')
        SQL);
    }

    public function down(): void
    {
        // Tidak dikembalikan ke TIMESTAMP (perilaku lama adalah bug)
    }
};
