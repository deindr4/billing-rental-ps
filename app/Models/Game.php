<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Game extends Model
{
    use BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    protected $fillable = [
        'tenant_id',
        'nama',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'game_unit')->withTimestamps();
    }
}
