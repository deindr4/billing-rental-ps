<?php

namespace App\Models;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Baris audit (hanya ditambah, tidak pernah diubah). Tulis lewat App\Support\Audit.
 */
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const LABEL_AKSI = [
        'dibuat' => 'Dibuat',
        'diubah' => 'Diubah',
        'dihapus' => 'Dihapus',
        'login' => 'Login',
        'login_gagal' => 'Login gagal',
        'logout' => 'Logout',
        'pin_disetujui' => 'PIN disetujui',
        'pin_gagal' => 'PIN salah',
        'batal_transaksi' => 'Batal transaksi',
        'waktu_gratis' => 'Tambah waktu gratis',
        'bypass_tv' => 'Bypass TV',
        'kode_darurat' => 'Kode darurat TV',
        'koreksi_member' => 'Koreksi member',
        'batal_pengeluaran' => 'Batal pengeluaran',
        'selisih_kas' => 'Selisih kas',
        'modal' => 'Suntikan modal',
        'prive' => 'Prive owner',
        'backup' => 'Backup',
        'pulihkan_backup' => 'Pulihkan backup',
    ];

    protected $table = 'audit_log';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'user_id', 'aksi', 'anomali', 'subjek_type', 'subjek_id',
        'keterangan', 'data', 'ip', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'anomali' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Filter tenant aktif (super admin tanpa tenant melihat semua)
        static::addGlobalScope('tenant', function (Builder $q) {
            $tenantId = app(Tenancy::class)->tenantId();

            if ($tenantId !== null) {
                $q->where($q->qualifyColumn('tenant_id'), $tenantId);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function subjek(): MorphTo
    {
        return $this->morphTo();
    }

    public function labelAksi(): string
    {
        return self::LABEL_AKSI[$this->aksi] ?? $this->aksi;
    }

    /** Nama model yang mudah dibaca: App\Models\PaketHarga -> Paket harga */
    public function labelSubjek(): ?string
    {
        if (! $this->subjek_type) {
            return null;
        }

        return ucfirst(mb_strtolower(trim(preg_replace('/(?<!^)[A-Z]/', ' $0', class_basename($this->subjek_type)))));
    }
}
