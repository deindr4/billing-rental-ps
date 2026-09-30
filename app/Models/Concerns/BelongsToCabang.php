<?php

namespace App\Models\Concerns;

use App\Models\Cabang;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data operasional milik satu cabang: otomatis difilter & diisi cabang_id.
 * Dipakai bersama BelongsToTenant.
 */
trait BelongsToCabang
{
    public static function bootBelongsToCabang(): void
    {
        static::addGlobalScope('cabang', function (Builder $query) {
            $cabangId = app(Tenancy::class)->cabangId();

            if ($cabangId !== null) {
                $query->where($query->qualifyColumn('cabang_id'), $cabangId);
            }
        });

        static::creating(function (Model $model) {
            if (empty($model->cabang_id)) {
                $model->cabang_id = app(Tenancy::class)->cabangId();
            }
        });
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }
}
