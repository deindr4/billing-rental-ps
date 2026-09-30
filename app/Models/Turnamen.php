<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Turnamen extends Model
{
    use BelongsToCabang, BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    public const STATUS = [
        'draft' => 'Draft',
        'pendaftaran' => 'Pendaftaran dibuka',
        'berjalan' => 'Berjalan',
        'selesai' => 'Selesai',
        'batal' => 'Dibatalkan',
    ];

    protected $table = 'turnamen';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'slug', 'nama', 'game', 'mulai_pada', 'biaya_daftar', 'kuota',
        'hadiah', 'aturan', 'daftar_online', 'status',
    ];

    protected function casts(): array
    {
        return [
            'mulai_pada' => 'datetime',
            'biaya_daftar' => 'integer',
            'kuota' => 'integer',
            'daftar_online' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function peserta(): HasMany
    {
        return $this->hasMany(TurnamenPeserta::class);
    }

    public function pertandingan(): HasMany
    {
        return $this->hasMany(TurnamenPertandingan::class)->orderBy('babak')->orderBy('nomor');
    }

    public function pesertaAktif(): HasMany
    {
        return $this->peserta()->where('status', '!=', 'batal');
    }

    public function penuh(): bool
    {
        return $this->pesertaAktif()->count() >= $this->kuota;
    }

    public function bisaDaftarOnline(): bool
    {
        return $this->daftar_online && $this->status === 'pendaftaran' && $this->mulai_pada->isFuture() && ! $this->penuh();
    }

    /** Nama babak dari jumlah babak total: Final, Semifinal, Perempat final, Babak 16 besar ... */
    public static function namaBabak(int $babak, int $totalBabak): string
    {
        return match ($totalBabak - $babak) {
            0 => 'Final',
            1 => 'Semifinal',
            2 => 'Perempat final',
            default => 'Babak '.(2 ** ($totalBabak - $babak + 1)).' besar',
        };
    }
}
