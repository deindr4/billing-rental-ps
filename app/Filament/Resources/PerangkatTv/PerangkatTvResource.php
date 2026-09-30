<?php

namespace App\Filament\Resources\PerangkatTv;

use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\PerangkatTv\Pages\ListPerangkatTv;
use App\Models\LogTv;
use App\Models\PerangkatTv;
use App\Services\Tv\KodeDarurat;
use App\Services\Tv\NotifikasiTv;
use App\Services\Tv\PairingTvService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Daftar TV yang menjalankan TV Agent: status online, unit, kode darurat, cabut.
 * Pairing dilakukan dari tombol "Pasangkan TV" di halaman daftar.
 */
class PerangkatTvResource extends Resource
{
    use ButuhIzin;

    protected static ?string $model = PerangkatTv::class;

    protected static ?string $slug = 'perangkat-tv';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static string|UnitEnum|null $navigationGroup = 'Rental';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Perangkat TV';

    protected static ?string $pluralModelLabel = 'Perangkat TV';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('unit:id,kode,nama'))
            ->poll('15s')
            ->columns([
                TextColumn::make('unit.nama')
                    ->label('Unit')
                    ->placeholder('Belum ada unit')
                    ->description(fn (PerangkatTv $r) => $r->namaTampil()),
                TextColumn::make('online')
                    ->label('Koneksi')
                    ->badge()
                    ->state(fn (PerangkatTv $r) => match (true) {
                        $r->status === PerangkatTv::STATUS_DICABUT => 'Dicabut',
                        $r->isOnline() => 'Online',
                        default => 'Offline',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Online' => 'success',
                        'Offline' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('layar')
                    ->label('Tampilan')
                    ->placeholder('-')
                    ->formatStateUsing(fn (?string $state) => $state ? str_replace('_', ' ', ucfirst($state)) : null),
                TextColumn::make('terakhir_online')
                    ->label('Terakhir terlihat')
                    ->since()
                    ->placeholder('Belum pernah'),
                TextColumn::make('versi_app')
                    ->label('Versi app')
                    ->placeholder('-')
                    ->description(fn (PerangkatTv $r) => $r->versi_android ? 'Android '.$r->versi_android : null),
                TextColumn::make('ip')->label('IP')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('status')
            ->recordActions([
                Action::make('inputHdmi')
                    ->label(fn (PerangkatTv $r) => $r->input_hdmi_label ?: 'Input HDMI')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color(fn (PerangkatTv $r) => $r->input_hdmi ? 'gray' : 'warning')
                    ->tooltip('HDMI tempat PS tersambung')
                    ->visible(fn (PerangkatTv $r) => $r->status === PerangkatTv::STATUS_AKTIF && $r->daftarInput() !== [])
                    ->modalHeading('PS tersambung ke HDMI berapa?')
                    ->modalDescription('Daftar input dilaporkan oleh TV. TV akan pindah ke input ini setiap sesi dimulai.')
                    ->fillForm(fn (PerangkatTv $r) => ['input' => $r->input_hdmi])
                    ->schema(fn (PerangkatTv $r) => [
                        Radio::make('input')
                            ->label('Input')
                            ->options($r->daftarInput())
                            ->required(),
                    ])
                    ->action(function (PerangkatTv $r, array $data) {
                        $label = $r->daftarInput()[$data['input']] ?? $data['input'];
                        $r->update(['input_hdmi' => $data['input'], 'input_hdmi_label' => $label]);

                        LogTv::catat($r, 'input_hdmi', ['label' => $label, 'lewat' => 'admin'], auth()->user());
                        NotifikasiTv::perangkat($r, 'input_hdmi');

                        Notification::make()->title("Input PS: {$label}")->success()->send();
                    }),

                Action::make('diagnostik')
                    ->label('Diagnostik')
                    ->icon('heroicon-o-cpu-chip')
                    ->color('gray')
                    ->visible(fn (PerangkatTv $r) => ! empty($r->diagnostik))
                    ->modalHeading(fn (PerangkatTv $r) => 'Diagnostik · '.($r->unit?->nama ?? $r->namaTampil()))
                    ->modalDescription(fn (PerangkatTv $r) => 'Dilaporkan TV '.$r->diagnostik_pada?->diffForHumans().'. Dipakai untuk memeriksa kemampuan TV tanpa menyambungkannya ke komputer.')
                    ->modalContent(fn (PerangkatTv $r) => new HtmlString(self::tabelDiagnostik($r->diagnostik)))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),

                Action::make('kodeDarurat')
                    ->label('Kode darurat')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->visible(fn (PerangkatTv $r) => $r->status === PerangkatTv::STATUS_AKTIF && $r->rahasia_offline && auth()->user()->can('tv.bypass'))
                    ->modalHeading('Kode darurat')
                    ->modalDescription('Untuk membuka TV saat jaringan/server mati. Ketik kode ini di TV. Kode berganti tiap 5 menit.')
                    ->modalContent(function (PerangkatTv $r) {
                        LogTv::catat($r, 'kode_darurat', [], auth()->user());

                        $kode = KodeDarurat::buat($r->rahasia_offline);
                        $menit = (int) ceil(KodeDarurat::sisaDetik() / 60);

                        return new HtmlString(
                            '<div style="text-align:center;padding:1rem 0;">'
                            .'<div style="font-size:2.5rem;font-weight:700;letter-spacing:.3em;font-family:monospace;">'.e($kode).'</div>'
                            .'<div style="opacity:.7;margin-top:.5rem;">Berlaku ± '.$menit.' menit lagi · '.e($r->unit?->nama ?? $r->namaTampil()).'</div>'
                            .'</div>'
                        );
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),

                Action::make('cabut')
                    ->label('Cabut')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (PerangkatTv $r) => $r->status === PerangkatTv::STATUS_AKTIF)
                    ->requiresConfirmation()
                    ->modalHeading('Cabut TV ini?')
                    ->modalDescription('TV tidak bisa lagi terhubung dan kembali ke layar pairing. Untuk memakai lagi, pasangkan ulang.')
                    ->action(function (PerangkatTv $r) {
                        app(PairingTvService::class)->cabut($r, auth()->user(), 'Dicabut dari panel admin');

                        Notification::make()->title('TV dicabut')->success()->send();
                    }),
            ])
            ->emptyStateHeading('Belum ada TV')
            ->emptyStateDescription('Buka aplikasi TV Agent di TV, lalu klik "Pasangkan TV" dan masukkan kode yang tampil di layar.');
    }

    /** Label ramah untuk kunci diagnostik dari APK */
    private const LABEL_DIAGNOSTIK = [
        'app' => 'Versi aplikasi',
        'android' => 'Android',
        'perangkat' => 'Perangkat',
        'build' => 'Build',
        'leanback' => 'Android TV (leanback)',
        'izin_overlay' => 'Izin tampil di atas (timer)',
        'izin_pasang_apk' => 'Izin pasang update',
        'launcher_utama' => 'Jadi layar utama (Home)',
        'bebas_hemat_baterai' => 'Bebas penghemat daya',
        'jumlah_hdmi' => 'Input HDMI terdeteksi',
        'input' => 'Daftar input',
        'error_hdmi' => 'Error pindah HDMI',
        'suara_terakhir' => 'Bunyi peringatan terakhir',
        'aplikasi_terpasang' => 'Aplikasi terpasang (nama = paket)',
        'fitur_device_admin' => 'TV mendukung standby dari aplikasi',
        'device_admin' => 'Izin matikan layar (Device admin)',
        'device_owner' => 'Bisa restart TV penuh (device owner)',
        'remote_terakhir' => 'Perintah remote terakhir',
    ];

    private static function tabelDiagnostik(array $diag): string
    {
        $baris = '';

        foreach ($diag as $kunci => $nilai) {
            $teks = match (true) {
                is_bool($nilai) => $nilai ? '<span style="color:#16a34a;font-weight:600;">Ya</span>' : '<span style="color:#dc2626;font-weight:600;">Tidak</span>',
                is_array($nilai) => implode('<br>', array_map('e', $nilai)) ?: '-',
                default => e((string) $nilai),
            };

            $baris .= '<tr><td style="padding:6px 12px 6px 0;opacity:.7;vertical-align:top;white-space:nowrap;">'.e(self::LABEL_DIAGNOSTIK[$kunci] ?? $kunci).'</td>'
                .'<td style="padding:6px 0;font-family:monospace;font-size:.85em;word-break:break-all;">'.$teks.'</td></tr>';
        }

        return '<table style="width:100%;border-collapse:collapse;">'.$baris.'</table>';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPerangkatTv::route('/'),
        ];
    }
}
