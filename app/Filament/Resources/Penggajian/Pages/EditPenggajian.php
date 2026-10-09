<?php

namespace App\Filament\Resources\Penggajian\Pages;

use App\Exceptions\BillingException;
use App\Filament\Resources\Penggajian\PenggajianResource;
use App\Models\Cabang;
use App\Models\Pengeluaran;
use App\Services\Karyawan\GajiService;
use App\Support\Tenancy;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/** Periksa rekap: centang potongan, penyesuaian → simpan (hitung ulang) → setujui → bayar; slip & batal */
class EditPenggajian extends EditRecord
{
    protected static string $resource = PenggajianResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $rincian = $this->record->rincian ?? [];
        $data['potongan_setuju'] = collect($rincian['usulan_potongan'] ?? [])->where('disetujui', true)->pluck('kunci')->all();
        $data['penyesuaian_daftar'] = $rincian['penyesuaian'] ?? [];

        return $data;
    }

    /** Simpan = hitung ulang dari data terbaru (absensi, shift) dengan pilihan owner */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(GajiService::class)->hitungUlang($record, $data['potongan_setuju'] ?? [], $data['penyesuaian_daftar'] ?? [], $data['catatan'] ?? null);
        } catch (BillingException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $this->halt();
        }
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan & hitung ulang')->visible(fn () => $this->record->bisaDiubah());
    }

    protected function getHeaderActions(): array
    {
        $layanan = fn () => app(GajiService::class);
        $jalankan = function (callable $aksi, string $pesan) {
            try {
                $aksi();
                Notification::make()->title($pesan)->success()->send();
                $this->redirect(PenggajianResource::getUrl('edit', ['record' => $this->record]));
            } catch (BillingException $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
            }
        };

        return [
            Action::make('slip')
                ->label('Slip gaji')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn () => route('gaji.slip', ['id' => $this->record->id]))
                ->openUrlInNewTab(),

            Action::make('setujui')
                ->label('Setujui')
                ->icon('heroicon-o-check')
                ->requiresConfirmation()
                ->modalDescription('Setelah disetujui, rincian tidak bisa diubah. Simpan perubahan dulu bila ada.')
                ->visible(fn () => $this->record->status === 'draft')
                ->action(fn () => $jalankan(fn () => $layanan()->setujui($this->record, auth()->user()), 'Rekap gaji disetujui')),

            Action::make('bayar')
                ->label('Bayar')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn () => $this->record->status === 'disetujui')
                ->schema([
                    Select::make('sumber_dana')->label('Dibayar dari')->options(Pengeluaran::SUMBER_DANA)->default('rekening')->required()
                        ->helperText('Kas laci: butuh shift terbuka di cabang ini & uang laci cukup.'),
                ])
                ->modalDescription(fn () => 'Total Rp '.number_format($this->record->total, 0, ',', '.').' dicatat sebagai pengeluaran kategori Gaji.')
                ->action(fn (array $data) => $jalankan(fn () => $layanan()->bayar(
                    $this->record, auth()->user(), $data['sumber_dana'], Cabang::findOrFail(app(Tenancy::class)->cabangId())
                ), 'Gaji dibayar & dicatat di pengeluaran')),

            Action::make('batal')
                ->label('Batalkan')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn () => $this->record->status !== 'batal')
                ->schema([Textarea::make('alasan')->required()->minLength(5)])
                ->modalDescription('Rekap yang sudah dibayar ikut membatalkan pengeluaran gajinya.')
                ->action(fn (array $data) => $jalankan(fn () => $layanan()->batal($this->record, auth()->user(), $data['alasan']), 'Rekap gaji dibatalkan')),
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        return PenggajianResource::getUrl('edit', ['record' => $this->record]);
    }
}
