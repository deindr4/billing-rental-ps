<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Mengisi kolom sinkronisasi: origin (local/cloud) dan version (naik setiap perubahan).
 */
trait HasSyncMeta
{
    public static function bootHasSyncMeta(): void
    {
        static::creating(function (Model $model) {
            if (empty($model->origin)) {
                $model->origin = config('app.mode') === 'cloud' ? 'cloud' : 'local';
            }

            if (empty($model->version)) {
                $model->version = 1;
            }
        });

        static::updating(function (Model $model) {
            if ($model->isDirty() && ! $model->isDirty('synced_at')) {
                $model->version = ((int) $model->getOriginal('version')) + 1;
            }
        });
    }
}
