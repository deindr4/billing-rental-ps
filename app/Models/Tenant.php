<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use Diaudit, HasUuids;

    protected $fillable = [
        'kode',
        'nama',
        'domain',
        'status',
        'max_cabang',
        'max_unit',
        'max_tv',
        'max_user',
        'langganan_berakhir',
    ];

    protected function casts(): array
    {
        return [
            'max_cabang' => 'integer',
            'max_unit' => 'integer',
            'max_tv' => 'integer',
            'max_user' => 'integer',
            'langganan_berakhir' => 'date',
        ];
    }

    public function cabang(): HasMany
    {
        return $this->hasMany(Cabang::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isAktif(): bool
    {
        return $this->status === 'aktif';
    }
}
