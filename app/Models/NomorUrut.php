<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NomorUrut extends Model
{
    use HasUuids;

    protected $table = 'nomor_urut';

    protected $fillable = ['tenant_id', 'cabang_id', 'prefix', 'sumber', 'tanggal', 'terakhir'];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'terakhir' => 'integer',
        ];
    }
}
