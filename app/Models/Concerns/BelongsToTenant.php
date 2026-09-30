<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data milik tenant: otomatis difilter & diisi tenant_id sesuai konteks aktif.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $tenantId = app(Tenancy::class)->tenantId();

            if ($tenantId !== null) {
                $query->where($query->qualifyColumn('tenant_id'), $tenantId);
            }
        });

        static::creating(function (Model $model) {
            if (empty($model->tenant_id)) {
                $model->tenant_id = app(Tenancy::class)->tenantId();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
