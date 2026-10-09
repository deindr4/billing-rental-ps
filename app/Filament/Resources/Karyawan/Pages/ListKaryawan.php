<?php

namespace App\Filament\Resources\Karyawan\Pages;

use App\Filament\Resources\Karyawan\KaryawanResource;
use App\Models\Karyawan;
use App\Models\User;
use App\Support\Tenancy;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListKaryawan extends ListRecords
{
    protected static string $resource = KaryawanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Pengguna (akun login) yang belum punya data karyawan dibuatkan otomatis
            Action::make('impor')
                ->label('Ambil dari pengguna')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Buat data karyawan untuk setiap akun login (selain super admin) yang belum punya. Data lain bisa dilengkapi sesudahnya.')
                ->action(function () {
                    $n = self::imporDariPengguna();
                    Notification::make()->title($n > 0 ? "{$n} karyawan ditambahkan" : 'Semua pengguna sudah punya data karyawan')->success()->send();
                }),
            CreateAction::make(),
        ];
    }

    public static function imporDariPengguna(): int
    {
        $tenantId = app(Tenancy::class)->tenantId();
        $sudah = Karyawan::query()->whereNotNull('user_id')->pluck('user_id');

        $users = User::query()->where('tenant_id', $tenantId)->where('is_super_admin', false)
            ->whereNotIn('id', $sudah)->with(['cabang:id', 'roles:id,name'])->get();

        foreach ($users as $u) {
            Karyawan::create([
                'tenant_id' => $tenantId,
                'user_id' => $u->id,
                'nama' => $u->name,
                'jabatan' => $u->roles->first()?->name,
                'telepon' => $u->phone,
                'cabang_id' => $u->cabang->count() === 1 ? $u->cabang->first()->id : null,
                'is_active' => (bool) ($u->is_active ?? true),
            ]);
        }

        return $users->count();
    }
}
