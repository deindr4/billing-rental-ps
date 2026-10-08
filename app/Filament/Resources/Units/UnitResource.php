<?php

namespace App\Filament\Resources\Units;

use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\Units\Pages\CreateUnit;
use App\Filament\Resources\Units\Pages\EditUnit;
use App\Filament\Resources\Units\Pages\ListUnits;
use App\Models\Unit;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class UnitResource extends Resource
{
    use ButuhIzin;

    protected static ?string $model = Unit::class;

    protected static ?string $slug = 'unit';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tv';

    protected static string|UnitEnum|null $navigationGroup = 'Rental';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Unit';

    protected static ?string $pluralModelLabel = 'Unit';

    protected static ?string $recordTitleAttribute = 'nama';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi unit')
                ->columns(2)
                ->schema([
                    TextInput::make('nama')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('TV 1 - PS5'),
                    TextInput::make('kode')
                        ->required()
                        ->maxLength(20)
                        ->placeholder('TV1')
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('cabang_id', app(Tenancy::class)->cabangId())),
                    Select::make('tipe_konsol_id')
                        ->label('Tipe konsol')
                        ->relationship('tipeKonsol', 'nama')
                        ->preload()
                        ->required(),
                    Select::make('kategori_unit_id')
                        ->label('Kategori')
                        ->relationship('kategori', 'nama')
                        ->preload()
                        ->placeholder('Tanpa kategori'),
                    TextInput::make('lokasi')
                        ->maxLength(100)
                        ->placeholder('Lantai 1'),
                    TextInput::make('urutan')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),
                    ColorPicker::make('warna')
                        ->label('Warna kartu')
                        ->regex('/^#[0-9a-fA-F]{6}$/')
                        ->helperText('Penanda unit di matriks kasir (strip kiri & kode). Kosongkan = otomatis dari palet sesuai urutan. Warna status tetap.'),
                ]),

            Section::make('Kontrol TV / PC')
                ->columns(2)
                ->schema([
                    Select::make('mode_kontrol')
                        ->label('Mode')
                        ->options([
                            'manual' => 'Manual (tanpa kontrol TV)',
                            'tv_agent' => 'Agent (TV Agent / PC kiosk)',
                        ])
                        ->default('manual')
                        ->required(),
                    Select::make('tipe_perangkat')
                        ->label('Tipe perangkat')
                        ->options(Unit::PERANGKAT)
                        ->default('tanpa_kontrol')
                        ->required(),
                    Select::make('posisi_timer')
                        ->label('Posisi timer di TV')
                        ->options(Unit::POSISI_TIMER)
                        ->placeholder('Ikut pengaturan cabang'),
                    TextInput::make('durasi_bypass_menit')
                        ->label('Durasi bypass default (menit)')
                        ->helperText('Minimal 5 menit. Muncul sebagai pilihan durasi saat bypass di unit ini.')
                        ->numeric()
                        ->minValue(5)
                        ->maxValue(1440)
                        ->placeholder('Ikut pengaturan cabang'),
                    FileUpload::make('wallpaper')
                        ->label('Wallpaper layar kunci khusus unit ini')
                        ->image()
                        ->disk('public')
                        ->directory(fn () => 'tenants/'.app(Tenancy::class)->tenantId().'/wallpaper')
                        ->maxSize(8192)
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                        ->imageResizeMode('cover')
                        ->imageResizeTargetWidth('1920')
                        ->imageResizeTargetHeight('1080')
                        ->helperText('Opsional, rasio 16:9. Otomatis dikompres (±100–200 KB). Kosongkan untuk memakai wallpaper default (Admin → Tampilan).')
                        ->columnSpanFull(),
                ]),

            Section::make('Lainnya')
                ->columns(2)
                ->schema([
                    Select::make('games')
                        ->label('Game terinstall')
                        ->relationship('games', 'nama')
                        ->multiple()
                        ->preload()
                        ->createOptionForm([
                            TextInput::make('nama')->required()->maxLength(100),
                        ])
                        ->columnSpanFull(),
                    Toggle::make('izinkan_booking')
                        ->label('Bisa dibooking online')
                        ->default(true)
                        ->inline(false),
                    Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true)
                        ->inline(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kode')->searchable()->sortable()
                    ->icon('heroicon-s-stop')
                    ->iconColor(fn (Unit $record) => \Filament\Support\Colors\Color::hex($record->warnaKartu())),
                TextColumn::make('nama')->searchable(),
                TextColumn::make('tipeKonsol.kode')->label('Konsol'),
                TextColumn::make('kategori.nama')->label('Kategori')->placeholder('-'),
                TextColumn::make('status')->badge(),
                TextColumn::make('mode_kontrol')
                    ->label('Kontrol')
                    ->formatStateUsing(fn (?string $state, Unit $record) => $state !== 'tv_agent' ? 'Manual'
                        : ($record->tipe_perangkat === 'windows_pc' ? 'PC Agent' : 'TV Agent')),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('urutan')
            ->filters([
                SelectFilter::make('tipe_konsol_id')
                    ->label('Tipe konsol')
                    ->relationship('tipeKonsol', 'nama'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUnits::route('/'),
            'create' => CreateUnit::route('/create'),
            'edit' => EditUnit::route('/{record}/edit'),
        ];
    }
}
