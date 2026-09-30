<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Member tingkat tenant (berlaku di semua cabang).
 * Kolom saldo/poin/stamp/total_belanja hanya diubah lewat MemberService (dicatat di member_mutasi).
 */
class Member extends Model
{
    use BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    /** Saldo/poin/stamp sudah tercatat di member_mutasi */
    protected array $auditAbaikan = ['saldo', 'poin', 'stamp', 'total_belanja', 'jumlah_kunjungan', 'tier', 'terakhir_kunjungan'];

    protected $fillable = [
        'tenant_id', 'cabang_id', 'kode', 'nama', 'telepon', 'email', 'tanggal_lahir', 'catatan', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'saldo' => 'integer',
            'poin' => 'integer',
            'stamp' => 'integer',
            'total_belanja' => 'integer',
            'jumlah_kunjungan' => 'integer',
            'tanggal_lahir' => 'date',
            'terakhir_kunjungan' => 'datetime',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function mutasi(): HasMany
    {
        return $this->hasMany(MemberMutasi::class);
    }

    public function transaksi(): HasMany
    {
        return $this->hasMany(Transaksi::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Cari berdasarkan nama, kode, atau nomor HP (0812 / 62812 / 812 dianggap sama) */
    public function scopeCari(Builder $query, ?string $kata): Builder
    {
        $kata = trim((string) $kata);

        if ($kata === '') {
            return $query;
        }

        $angka = preg_replace('/\D/', '', $kata);

        return $query->where(function (Builder $q) use ($kata, $angka) {
            $q->where('nama', 'like', "%{$kata}%")->orWhere('kode', 'like', "%{$kata}%");

            if (strlen($angka) >= 4) {
                $q->orWhere('telepon', 'like', '%'.preg_replace('/^(62|0)/', '', $angka).'%');
            }
        });
    }

    /** Normalisasi nomor HP ke format 08xx */
    public static function normalisasiTelepon(string $telepon): string
    {
        $angka = preg_replace('/\D/', '', $telepon);

        if (str_starts_with($angka, '62')) {
            $angka = '0'.substr($angka, 2);
        } elseif (str_starts_with($angka, '8')) {
            $angka = '0'.$angka;
        }

        return $angka;
    }

    public function inisial(): string
    {
        $kata = preg_split('/\s+/', trim($this->nama)) ?: [];

        return mb_strtoupper(mb_substr($kata[0] ?? '?', 0, 1).mb_substr($kata[1] ?? '', 0, 1));
    }
}
