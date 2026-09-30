<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Model yang datanya tidak boleh dihapus, hanya dibatalkan.
 */
trait TidakBisaDihapus
{
    public static function bootTidakBisaDihapus(): void
    {
        static::deleting(function () {
            throw new LogicException('Data ini tidak bisa dihapus. Gunakan pembatalan.');
        });
    }
}
