<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pencatat audit log.
 *
 * Audit::catat('bypass_tv', 'Bypass TV 1 selama 30 menit', $perangkat, ['menit' => 30], anomali: true);
 *
 * Gagal mencatat audit tidak boleh menggagalkan transaksi utama (dilaporkan ke log aplikasi saja).
 */
final class Audit
{
    /** Aksi yang otomatis dianggap anomali (tampil di laporan) */
    public const ANOMALI = [
        'pin_gagal', 'batal_transaksi', 'waktu_gratis', 'bypass_tv', 'kode_darurat',
        'koreksi_member', 'batal_pengeluaran', 'selisih_kas', 'login_gagal',
    ];

    private static bool $nonaktif = false;

    public static function catat(
        string $aksi,
        string $keterangan,
        ?Model $subjek = null,
        array $data = [],
        ?bool $anomali = null,
        ?string $tenantId = null,
        ?string $cabangId = null,
        ?string $userId = null,
    ): ?AuditLog {
        if (self::$nonaktif) {
            return null;
        }

        try {
            $tenancy = app(Tenancy::class);
            $request = app()->runningInConsole() ? null : request();

            return AuditLog::create([
                'tenant_id' => $tenantId ?? $subjek?->getAttribute('tenant_id') ?? $tenancy->tenantId(),
                'cabang_id' => $cabangId ?? $subjek?->getAttribute('cabang_id') ?? $tenancy->cabangId(),
                'user_id' => $userId ?? auth()->id(),
                'aksi' => $aksi,
                'anomali' => $anomali ?? in_array($aksi, self::ANOMALI, true),
                'subjek_type' => $subjek?->getMorphClass(),
                'subjek_id' => $subjek?->getKey(),
                'keterangan' => Str::limit($keterangan, 250),
                'data' => $data ?: null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Jalankan tanpa audit otomatis (misal seeder / impor massal) */
    public static function tanpa(callable $fn): mixed
    {
        $sebelum = self::$nonaktif;
        self::$nonaktif = true;

        try {
            return $fn();
        } finally {
            self::$nonaktif = $sebelum;
        }
    }
}
