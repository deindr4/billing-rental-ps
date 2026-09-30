<?php

namespace App\Filament\Concerns;

/**
 * Resource Filament hanya bisa dibuka user yang punya izin tertentu.
 * Ganti izin dengan meng-override izinAkses() di resource.
 */
trait ButuhIzin
{
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(static::izinAkses());
    }

    protected static function izinAkses(): string
    {
        return 'admin.master';
    }
}
