<?php

namespace App\Filament\Resources\PerangkatTv;

use App\Exceptions\BillingException;
use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\PerangkatTv\Pages\ListPerangkatTv;
use App\Models\LogTv;
use App\Models\PerangkatTv;
use App\Models\RilisApk;
use App\Services\Tv\HdmiTvService;
use App\Services\Tv\KodeDarurat;
use App\Services\Tv\NotifikasiTv;
use App\Services\Tv\PairingTvService;
use App\Services\Tv\TvRemoteService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
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
                TextColumn::make('ping')
                    ->label('Respon server')
                    ->placeholder('-')
                    ->state(fn (PerangkatTv $r) => self::teksPing($r))
                    ->color(fn (PerangkatTv $r) => self::warnaPing($r->server_dipakai === 'cloud' ? $r->ping_cloud_ms : $r->ping_lokal_ms))
                    ->description(fn (PerangkatTv $r) => $r->server_dipakai ? 'Memakai server '.$r->server_dipakai : null)
                    ->tooltip('Waktu respon TV ke server lokal (L) & cloud (C). Cloud dicek tiap 5 menit. APK TV ≥ 0.6.5.'),
                TextColumn::make('terakhir_online')
                    ->label('Terakhir terlihat')
                    ->since()
                    ->placeholder('Belum pernah'),
                TextColumn::make('versi_app')
                    ->label('Versi app')
                    ->placeholder('-')
                    ->badge()
                    ->color(fn (PerangkatTv $r) => self::perluUpdate($r) ? 'warning' : 'success')
                    ->description(fn (PerangkatTv $r) => self::perluUpdate($r)
                        ? 'Terbaru '.self::versiTerbaru()
                        : ($r->versi_android ? 'Android '.$r->versi_android : null)),
                TextColumn::make('ip')->label('IP')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('status')
            ->toolbarActions([
                BulkAction::make('pushUpdate')
                    ->label('Push update ke TV terpilih')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalHeading('Push update APK')
                    ->modalDescription(fn () => self::keteranganPush())
                    ->schema([self::isianPaksa()])
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records, array $data) => self::push($records, (bool) $data['paksa'])),
            ])
            ->recordActions([
                Action::make('pushUpdate')
                    ->label('Push update')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->visible(fn (PerangkatTv $r) => self::perluUpdate($r))
                    ->modalHeading(fn (PerangkatTv $r) => 'Update '.($r->unit?->nama ?? $r->namaTampil()).' ke '.self::versiTerbaru())
                    ->modalDescription(fn () => self::keteranganPush())
                    ->schema([self::isianPaksa()])
                    ->action(fn (PerangkatTv $r, array $data) => self::push([$r], (bool) $data['paksa'])),

                Action::make('inputHdmi')
                    ->label(fn (PerangkatTv $r) => $r->input_hdmi_label ?: 'Input HDMI')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color(fn (PerangkatTv $r) => $r->input_hdmi ? 'gray' : 'warning')
                    ->tooltip('HDMI tempat PS tersambung')
                    ->visible(fn (PerangkatTv $r) => $r->status === PerangkatTv::STATUS_AKTIF && $r->daftarInput() !== [])
                    ->modalHeading('HDMI & konsol di TV ini')
                    ->modalDescription('Daftar input dilaporkan oleh TV. Beri nama konsol tiap HDMI (mis. PS3 / PS4 / PS5) — kasir memilih / memindah HDMI dengan nama ini. '
                        .'"Dipakai sekarang" = HDMI yang dibuka setiap sesi dimulai; kasir bisa menggantinya dari kartu unit.')
                    ->fillForm(fn (PerangkatTv $r) => ['input' => $r->input_hdmi] + collect(array_keys($r->daftarInput()))
                        ->mapWithKeys(fn ($id, $i) => ["nama_{$i}" => ($r->hdmi_nama ?? [])[$id] ?? null])->all())
                    ->schema(fn (PerangkatTv $r) => [
                        // Id input berisi titik/garis miring, jadi nama isian memakai urutan (nama_0, nama_1, ...)
                        ...collect($r->daftarInput())->values()->map(fn ($label, $i) => TextInput::make("nama_{$i}")
                            ->label("Konsol di {$label}")
                            ->placeholder('Kosong / PS3 / PS4 / PS5')
                            ->maxLength(30))->all(),
                        Radio::make('input')
                            ->label('Dipakai sekarang')
                            ->options(fn () => $r->pilihanHdmi())
                            ->required(),
                    ])
                    ->action(function (PerangkatTv $r, array $data) {
                        $layanan = app(HdmiTvService::class);
                        $ids = array_keys($r->daftarInput());
                        $layanan->namai($r, collect($ids)->mapWithKeys(fn ($id, $i) => [$id => $data["nama_{$i}"] ?? null])->all());

                        try {
                            $label = $layanan->pindah($r->refresh(), $data['input'], auth()->user(), 'admin');
                        } catch (BillingException $e) {
                            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title("HDMI dipakai: {$label}")->success()->send();
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

    /* ---------------- Push update APK ---------------- */

    /** Versi rilis APK terbaru yang ditawarkan (null = belum ada rilis) */
    public static function versiTerbaru(): ?string
    {
        return once(fn () => RilisApk::aktif()->orderByDesc('versi_kode')->value('versi_nama'));
    }

    public static function perluUpdate(PerangkatTv $r): bool
    {
        return $r->status === PerangkatTv::STATUS_AKTIF && self::versiTerbaru() && $r->versi_app !== self::versiTerbaru();
    }

    /** "L 12 ms · C 85 ms" (L putus = tidak terjangkau). Kosong untuk TV offline / APK < 0.6.5 (angka lama menyesatkan). */
    public static function teksPing(PerangkatTv $r): ?string
    {
        if (! $r->isOnline() || ($r->ping_lokal_ms === null && $r->ping_cloud_ms === null)) {
            return null;
        }

        $teks = fn (?int $ms) => match (true) {
            $ms === null => null,
            $ms < 0 => 'putus',
            default => $ms.' ms',
        };

        return collect(['L' => $teks($r->ping_lokal_ms), 'C' => $teks($r->ping_cloud_ms)])
            ->filter()
            ->map(fn ($v, $k) => "{$k} {$v}")
            ->implode(' · ');
    }

    /** Sama dengan titik di TV: ≤ 300 ms hijau, lebih lambat kuning, tidak terjangkau merah */
    public static function warnaPing(?int $ms): string
    {
        return match (true) {
            $ms === null => 'gray',
            $ms < 0 => 'danger',
            $ms <= 300 => 'success',
            default => 'warning',
        };
    }

    public static function keteranganPush(): string
    {
        return 'TV mengunduh APK '.self::versiTerbaru().', lalu menampilkan layar pemasangan Android — tekan "Instal" dengan remote '
            .'(aturan keamanan Android). TV yang sedang dipakai menunggu sampai sesinya selesai, kecuali dipilih "sekarang juga". TV yang sudah terbaru dilewati.';
    }

    public static function isianPaksa(): Toggle
    {
        return Toggle::make('paksa')
            ->label('Pasang sekarang juga walau TV sedang dipakai')
            ->helperText('Layar pemasangan akan menutupi game pelanggan. Pakai hanya untuk perbaikan mendesak.')
            ->default(false);
    }

    /** @param iterable<PerangkatTv> $perangkat */
    public static function push(iterable $perangkat, bool $paksa): void
    {
        try {
            $n = app(TvRemoteService::class)->pushUpdate($perangkat, auth()->user(), $paksa);
        } catch (BillingException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title($n ? "Update dikirim ke {$n} TV" : 'Semua TV sudah memakai versi terbaru')
            ->body($n ? 'TV yang online menerima dalam beberapa detik. Kolom "Versi app" berubah setelah TV terpasang versi baru.' : null)
            ->{$n ? 'success' : 'info'}()->send();
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
        'izin_kunci_remote' => 'Izin kunci remote (Aksesibilitas)',
        'tombol_ditahan' => 'Tombol remote yang ditahan saat main',
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
