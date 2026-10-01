<?php

namespace App\Filament\Resources\RilisApk;

use App\Filament\Resources\RilisApk\Pages\CreateRilisApk;
use App\Filament\Resources\RilisApk\Pages\EditRilisApk;
use App\Filament\Resources\RilisApk\Pages\ListRilisApk;
use App\Models\PerangkatTv;
use App\Models\RilisApk;
use App\Services\Tv\RilisApkService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

/**
 * Rilis APK TV Agent. Satu aplikasi untuk semua tenant, jadi hanya super admin yang mengelola.
 */
class RilisApkResource extends Resource
{
    protected static ?string $model = RilisApk::class;

    protected static ?string $slug = 'rilis-apk';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $modelLabel = 'Rilis APK TV';

    protected static ?string $pluralModelLabel = 'Rilis APK TV';

    /** Super admin mengelola rilis; owner hanya melihat & mem-push update ke TV miliknya */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->hasRole('Owner'));
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    /** TV yang terlihat oleh user: super admin semua tenant, owner hanya miliknya */
    public static function perangkat(): Builder
    {
        return auth()->user()?->isSuperAdmin() ? PerangkatTv::withoutGlobalScopes() : PerangkatTv::query();
    }

    public static function getNavigationGroup(): ?string
    {
        return auth()->user()?->isSuperAdmin() ? 'Platform' : 'Rental';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('File APK')
                ->visibleOn('create')
                ->schema([
                    FileUpload::make('file')
                        ->label('APK TV Agent')
                        ->disk(RilisApkService::DISK)
                        ->directory(RilisApkService::FOLDER)
                        ->visibility('private')
                        ->acceptedFileTypes([
                            'application/vnd.android.package-archive',
                            'application/zip',
                            'application/java-archive',
                            'application/octet-stream',
                        ])
                        ->rules(['extensions:apk'])
                        ->maxSize(200 * 1024)
                        ->required()
                        ->helperText('File .apk hasil build release (ditandatangani). Maks. 200 MB.'),
                ]),

            Section::make('Versi')
                ->columns(2)
                ->schema([
                    TextInput::make('versi_nama')
                        ->label('Versi (versionName)')
                        ->placeholder('0.2.0')
                        ->regex('/^\d+\.\d+\.\d+$/')
                        ->required()
                        ->disabledOn('edit'),
                    TextInput::make('versi_kode')
                        ->label('Kode versi (versionCode)')
                        ->helperText(fn () => 'Harus sama dengan versionCode di APK dan lebih besar dari rilis sebelumnya ('.(RilisApk::max('versi_kode') ?? 0).').')
                        ->integer()
                        ->minValue(fn (string $operation) => $operation === 'create' ? (int) RilisApk::max('versi_kode') + 1 : 1)
                        ->unique(ignoreRecord: true)
                        ->required()
                        ->disabledOn('edit'),
                    Textarea::make('catatan')
                        ->label('Catatan rilis')
                        ->rows(3)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                    Toggle::make('wajib')
                        ->label('Update wajib')
                        ->helperText('TV harus update sebelum bisa dipakai (untuk perbaikan penting).'),
                    Toggle::make('aktif')
                        ->label('Ditawarkan ke TV')
                        ->helperText('Matikan untuk menarik rilis yang bermasalah.')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('versi_kode', 'desc')
            ->columns([
                TextColumn::make('versi_nama')->label('Versi')->description(fn (RilisApk $r) => 'kode '.$r->versi_kode),
                TextColumn::make('ukuran')->formatStateUsing(fn (int $state) => Number::fileSize($state, 1)),
                IconColumn::make('wajib')->boolean(),
                IconColumn::make('aktif')->label('Ditawarkan')->boolean(),
                TextColumn::make('terpasang')
                    ->label('TV terpasang')
                    ->state(fn (RilisApk $r) => self::perangkat()->aktif()->where('versi_app', $r->versi_nama)->count()),
                TextColumn::make('catatan')->label('Catatan')->limit(60)->placeholder('-')->wrap(),
                TextColumn::make('created_at')->label('Dirilis')->since(),
                TextColumn::make('sha256')->label('SHA-256')->limit(16)->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('tarik')
                    ->label('Tarik')
                    ->icon('heroicon-o-hand-raised')
                    ->color('danger')
                    ->visible(fn (RilisApk $r) => $r->aktif && auth()->user()->isSuperAdmin())
                    ->requiresConfirmation()
                    ->modalHeading(fn (RilisApk $r) => 'Tarik rilis '.$r->versi_nama.'?')
                    ->modalDescription('Rilis berhenti ditawarkan: TV yang belum memasang tidak akan mengunduhnya (update tertunda dibatalkan). '
                        .'TV yang SUDAH terpasang versi ini tetap memakainya — kembalikan dengan tombol "Rollback" pada versi lama.')
                    ->action(function (RilisApk $r) {
                        $r->update(['aktif' => false]);
                        app(RilisApkService::class)->beriTahuSemuaTv();
                        Notification::make()->title('Rilis '.$r->versi_nama.' ditarik')->success()->send();
                    }),

                Action::make('rollback')
                    ->label('Rollback ke versi ini')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (RilisApk $r) => auth()->user()->isSuperAdmin() && $r->versi_kode < (int) RilisApk::max('versi_kode'))
                    ->modalHeading(fn (RilisApk $r) => 'Kembali ke versi '.$r->versi_nama)
                    ->modalContent(fn (RilisApk $r) => new HtmlString(self::panduanRollback($r)))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),

                Action::make('unduh')
                    ->label('Unduh')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn (RilisApk $r) => Storage::disk(RilisApkService::DISK)->download($r->file, 'tv-agent-'.$r->versi_nama.'.apk')),
                EditAction::make(),
                DeleteAction::make()->after(fn (RilisApk $r) => app(RilisApkService::class)->hapusFile($r)),
            ]);
    }

    /**
     * Android menolak memasang versionCode yang lebih kecil (downgrade) kecuali aplikasi sistem.
     * Rollback = build ulang kode versi lama dengan versionCode baru (tv-agent/build-rollback.ps1), lalu rilis wajib + push.
     */
    public static function panduanRollback(RilisApk $r): string
    {
        $kode = (int) RilisApk::max('versi_kode') + 1;
        $terbaru = (string) RilisApk::orderByDesc('versi_kode')->value('versi_nama');
        $bagian = array_map('intval', explode('.', $terbaru ?: '0.0.0')) + [0, 0, 0];
        $nama = $bagian[0].'.'.$bagian[1].'.'.($bagian[2] + 1);
        $perintah = "powershell -ExecutionPolicy Bypass -File build-rollback.ps1 -Dari {$r->versi_nama} -Kode {$kode} -Nama {$nama}";

        return '<div style="font-size:14px; line-height:1.6;">'
            .'<p>Android <b>tidak mengizinkan</b> memasang versi dengan nomor lebih kecil di atas versi yang lebih baru '
            .'(hanya aplikasi sistem yang bisa). Uninstall lalu pasang ulang akan menghapus pairing semua TV.</p>'
            .'<p style="margin-top:8px;">Caranya: kode versi '.e($r->versi_nama).' di-build ulang dengan nomor versi baru, '
            .'jadi TV melihatnya sebagai update biasa.</p>'
            .'<ol style="margin:10px 0 0 18px; list-style:decimal;">'
            .'<li><b>Tarik</b> rilis yang bermasalah (tombol Tarik di daftar) supaya TV lain tidak ikut mengunduhnya.</li>'
            .'<li>Di PC pengembang, folder <code>tv-agent</code>, jalankan:'
            .'<pre style="white-space:pre-wrap; word-break:break-all; font-size:12px; padding:8px; border-radius:6px; background:rgba(127,127,127,.12); margin:6px 0; user-select:all;">'.e($perintah).'</pre>'
            .'Hasilnya <code>tv-agent\\rilis\\tv-agent-'.e($nama).'.apk</code>.</li>'
            .'<li><b>Unggah rilis baru</b>: versi <b>'.e($nama).'</b>, kode <b>'.$kode.'</b>, catatan "Rollback ke '.e($r->versi_nama).'", centang <b>Update wajib</b>.</li>'
            .'<li><b>Push update ke semua TV</b> dengan pilihan <b>pasang sekarang juga</b>.</li>'
            .'</ol></div>';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRilisApk::route('/'),
            'create' => CreateRilisApk::route('/create'),
            'edit' => EditRilisApk::route('/{record}/edit'),
        ];
    }
}
