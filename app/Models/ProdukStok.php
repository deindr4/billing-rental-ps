<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProdukStok extends Model
{
    use BelongsToCabang, BelongsToTenant, HasUuids;

    protected $table = 'produk_stok';

    protected $fillable = ['tenant_id', 'cabang_id', 'produk_id', 'qty', 'hpp_rata'];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'hpp_rata' => 'integer',
        ];
    }

    public function produk(): BelongsTo
    {
        return $this->belongsTo(Produk::class);
    }
}
