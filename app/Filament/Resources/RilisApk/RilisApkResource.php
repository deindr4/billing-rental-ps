<?php

namespace App\Filament\Resources\RilisApk;

use App\Filament\Resources\RilisApk\Pages\CreateRilisApk;
use App\Filament\Resources\RilisApk\Pages\EditRilisApk;
use App\Filament\Resources\RilisApk\Pages\ListRilisApk;
use App\Models\PerangkatTv;
use App\Models\RilisApk;
use App\Services\Tv\RilisApkService;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;
use UnitEnum;

/**
 * Rilis APK TV Agent. Satu aplikasi untuk semua tenant, jadi hanya super admin yang mengelola.
 */
class RilisApkResource extends Resource
{
    protected static ?string $model = RilisApk::class;

    protected static ?string $slug = 'rilis-apk';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $modelLabel = 'Rilis APK TV';

    protected static ?string $pluralModelLabel = 'Rilis APK TV';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
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
                    ->state(fn (RilisApk $r) => PerangkatTv::withoutGlobalScopes()->aktif()->where('versi_app', $r->versi_nama)->count()),
                TextColumn::make('created_at')->label('Dirilis')->since(),
                TextColumn::make('sha256')->label('SHA-256')->limit(16)->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->after(fn (RilisApk $r) => app(RilisApkService::class)->hapusFile($r)),
            ]);
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
