<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Cabang extends Model
{
    use Diaudit, HasUuids;

    protected $table = 'cabang';

    protected $fillable = [
        'tenant_id',
        'kode',
        'nama',
        'alamat',
        'telepon',
        'zona_waktu',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'cabang_user')->withTimestamps();
    }
}
