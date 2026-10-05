<?php

namespace App\Filament\Resources\PerangkatTv\Pages;

use App\Exceptions\BillingException;
use App\Filament\Resources\PerangkatTv\PerangkatTvResource;
use App\Models\PerangkatTv;
use App\Models\Unit;
use App\Services\Tv\PairingTvService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPerangkatTv extends ListRecords
{
    protected static string $resource = PerangkatTvResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pushUpdateSemua')
                ->label('Push update ke semua TV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => PerangkatTv::query()->aktif()->get(['id', 'status', 'versi_app'])->contains(fn ($p) => PerangkatTvResource::perluUpdate($p)))
                ->modalHeading(fn () => 'Push update APK '.PerangkatTvResource::versiTerbaru().' ke semua TV')
                ->modalDescription(fn () => PerangkatTvResource::keteranganPush())
                ->schema([PerangkatTvResource::isianPaksa()])
                ->modalSubmitActionLabel('Push')
                ->action(fn (array $data) => PerangkatTvResource::push(PerangkatTv::query()->aktif()->get(), (bool) $data['paksa'])),

            Action::make('pasangkan')
                ->label('Pasangkan TV / PC')
                ->icon('heroicon-o-link')
                ->modalHeading('Pasangkan TV / PC ke unit')
                ->modalDescription('Buka aplikasi TV Agent di TV, atau agen kiosk di PC. Kode 6 angka akan tampil di layar. Unit PC hanya untuk kode dari PC.')
                ->modalSubmitActionLabel('Pasangkan')
                ->schema([
                    TextInput::make('kode')
                        ->label('Kode di layar TV / PC')
                        ->required()
                        ->regex('/^\d{6}$/')
                        ->validationMessages(['regex' => 'Kode terdiri dari 6 angka.'])
                        ->extraInputAttributes(['inputmode' => 'numeric', 'maxlength' => 6])
                        ->placeholder('123456')
                        ->autofocus(),
                    Select::make('unit_id')
                        ->label('Unit')
                        ->options(fn () => $this->pilihanUnit())
                        ->searchable()
                        ->required()
                        ->helperText('Jika unit sudah punya perangkat, yang lama otomatis dicabut.'),
                ])
                ->action(function (array $data, Action $action) {
                    $unit = Unit::aktif()->find($data['unit_id']);

                    if (! $unit) {
                        Notification::make()->title('Unit tidak ditemukan')->danger()->send();
                        $action->halt();
                    }

                    try {
                        $perangkat = app(PairingTvService::class)->pasangkan($data['kode'], $unit, auth()->user());
                    } catch (BillingException $e) {
                        Notification::make()->title('Gagal memasangkan')->body($e->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()
                        ->title(($perangkat->isPc() ? 'PC' : 'TV').' terpasang ke '.$unit->nama)
                        ->body($perangkat->namaTampil().' akan tersambung dalam beberapa detik.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /** Unit aktif, ditandai jika sudah punya TV */
    private function pilihanUnit(): array
    {
        $sudahAda = PerangkatTv::query()->aktif()->whereNotNull('unit_id')->pluck('unit_id')->flip();

        return Unit::aktif()
            ->urut()
            ->with('tipeKonsol:id,jenis')
            ->get(['id', 'kode', 'nama', 'tipe_konsol_id'])
            ->mapWithKeys(fn (Unit $u) => [
                $u->id => $u->nama.($u->isPc() ? ' · PC' : '').($sudahAda->has($u->id) ? ' (sudah ada perangkat)' : ''),
            ])
            ->all();
    }
}
