<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogTv extends Model
{
    use BelongsToCabang, BelongsToTenant, HasUuids;

    public const LABEL = [
        'pairing' => 'Dipasangkan',
        'dicabut' => 'Dicabut',
        'bypass' => 'Bypass',
        'bypass_akhir' => 'Bypass diakhiri',
        'perintah' => 'Perintah remote',
        'kode_darurat' => 'Kode darurat dilihat',
        'panggil_kasir' => 'Pelanggan memanggil kasir',
        'input_hdmi' => 'Input HDMI PS diatur',
        'menu_staf' => 'Menu staf TV dibuka (PIN)',
    ];

    protected $table = 'log_tv';

    protected $fillable = ['tenant_id', 'cabang_id', 'perangkat_id', 'unit_id', 'user_id', 'jenis', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function perangkat(): BelongsTo
    {
        return $this->belongsTo(PerangkatTv::class, 'perangkat_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function catat(PerangkatTv $perangkat, string $jenis, array $data = [], ?User $user = null): self
    {
        return static::withoutGlobalScopes()->create([
            'tenant_id' => $perangkat->tenant_id,
            'cabang_id' => $perangkat->cabang_id,
            'perangkat_id' => $perangkat->id,
            'unit_id' => $perangkat->unit_id,
            'user_id' => $user?->id,
            'jenis' => $jenis,
            'data' => $data ?: null,
        ]);
    }
}
