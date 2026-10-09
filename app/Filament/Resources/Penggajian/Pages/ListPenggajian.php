<?php

namespace App\Filament\Resources\Penggajian\Pages;

use App\Exceptions\BillingException;
use App\Filament\Resources\Penggajian\PenggajianResource;
use App\Models\Cabang;
use App\Models\Karyawan;
use App\Services\Karyawan\GajiService;
use App\Support\Tenancy;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;

class ListPenggajian extends ListRecords
{
    protected static string $resource = PenggajianResource::class;

    protected function getHeaderActions(): array
    {
        [$dari, $sampai] = GajiService::periodeBawaan('bulanan');

        return [
            Action::make('buat')
                ->label('Buat rekap')
                ->icon('heroicon-o-plus')
                ->modalHeading('Buat rekap gaji')
                ->modalDescription('Rekap dibuat sebagai draft per karyawan; periksa, centang potongan, lalu setujui & bayar.')
                ->schema([
                    Select::make('jenis')
                        ->label('Periode gaji')
                        ->options(Karyawan::PERIODE)
                        ->default('bulanan')
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set) {
                            [$d, $s] = GajiService::periodeBawaan($state ?? 'bulanan');
                            $set('dari', $d->toDateString());
                            $set('sampai', $s->toDateString());
                            $set('karyawan', []);
                        }),
                    DatePicker::make('dari')->default($dari->toDateString())->required(),
                    DatePicker::make('sampai')->default($sampai->toDateString())->required(),
                    CheckboxList::make('karyawan')
                        ->label('Karyawan')
                        ->options(fn ($get) => Karyawan::aktif()->where('periode_gaji', $get('jenis') ?? 'bulanan')->orderBy('nama')->pluck('nama', 'id'))
                        ->bulkToggleable()
                        ->required()
                        ->helperText('Hanya karyawan aktif dengan periode gaji yang dipilih.'),
                ])
                ->action(function (array $data) {
                    $layanan = app(GajiService::class);
                    $cabang = Cabang::findOrFail(app(Tenancy::class)->cabangId());
                    [$ok, $gagal] = [0, []];

                    foreach (Karyawan::query()->whereIn('id', $data['karyawan'])->get() as $k) {
                        try {
                            $layanan->buat($k, Carbon::parse($data['dari']), Carbon::parse($data['sampai']), auth()->user(), $cabang);
                            $ok++;
                        } catch (BillingException $e) {
                            $gagal[] = $e->getMessage();
                        }
                    }

                    Notification::make()
                        ->title("{$ok} rekap dibuat")
                        ->body($gagal ? implode("\n", $gagal) : null)
                        ->{$gagal ? 'warning' : 'success'}()
                        ->send();
                }),
        ];
    }
}
