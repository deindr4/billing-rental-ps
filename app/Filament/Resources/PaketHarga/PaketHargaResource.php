<?php

namespace App\Filament\Resources\PaketHarga;

use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\PaketHarga\Pages\CreatePaketHarga;
use App\Filament\Resources\PaketHarga\Pages\EditPaketHarga;
use App\Filament\Resources\PaketHarga\Pages\ListPaketHarga;
use App\Models\Cabang;
use App\Models\PaketHarga;
use App\Models\Sesi;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class PaketHargaResource extends Resource
{
    use ButuhIzin;

    protected static ?string $model = PaketHarga::class;

    protected static ?string $slug = 'paket-harga';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Rental';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Paket Harga';

    protected static ?string $pluralModelLabel = 'Paket Harga';

    protected static ?string $recordTitleAttribute = 'nama';

    public const JENIS = [
        'per_jam' => 'Tarif per jam',
        'paket' => 'Paket (durasi tetap)',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('nama')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('Paket 3 Jam PS4'),
                    Select::make('jenis')
                        ->options(self::JENIS)
                        ->default('paket')
                        ->required()
                        ->live(),
                    TextInput::make('durasi_menit')
                        ->label('Durasi (menit)')
                        ->numeric()
                        ->minValue(1)
                        ->required(fn ($get) => $get('jenis') === 'paket')
                        ->visible(fn ($get) => $get('jenis') === 'paket'),
                    TextInput::make('harga')
                        ->label(fn ($get) => $get('jenis') === 'per_jam' ? 'Harga per jam' : 'Harga paket')
                        ->prefix('Rp')
                        ->mask(RawJs::make("\$money(\$input, ',', '.', 0)"))
                        ->stripCharacters('.')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    Select::make('tipe_konsol_id')
                        ->label('Tipe konsol')
                        ->relationship('tipeKonsol', 'nama')
                        ->preload()
                        ->placeholder('Semua tipe konsol'),
                    Select::make('kategori_unit_id')
                        ->label('Kategori unit')
                        ->relationship('kategori', 'nama')
                        ->preload()
                        ->placeholder('Semua kategori'),
                    Select::make('cabang_id')
                        ->label('Berlaku di cabang')
                        ->options(fn () => Cabang::query()
                            ->where('tenant_id', app(Tenancy::class)->tenantId())
                            ->orderBy('kode')
                            ->pluck('nama', 'id'))
                        ->placeholder('Semua cabang'),
                    TextInput::make('urutan')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),
                    TextInput::make('keterangan')
                        ->maxLength(255)
                        ->placeholder('Contoh: + 1 Es Teh')
                        ->columnSpanFull(),
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
                TextColumn::make('nama')->searchable(),
                TextColumn::make('jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::JENIS[$state] ?? $state),
                TextColumn::make('tipeKonsol.kode')->label('Konsol')->placeholder('Semua'),
                TextColumn::make('durasi_menit')
                    ->label('Durasi')
                    ->formatStateUsing(fn ($state) => $state ? Sesi::formatDurasi((int) $state * 60) : '-'),
                TextColumn::make('harga')
                    ->formatStateUsing(fn ($state) => 'Rp '.number_format((int) $state, 0, ',', '.'))
                    ->sortable(),
                TextColumn::make('cabang.nama')->label('Cabang')->placeholder('Semua'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('urutan')
            ->filters([
                SelectFilter::make('jenis')->options(self::JENIS),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaketHarga::route('/'),
            'create' => CreatePaketHarga::route('/create'),
            'edit' => EditPaketHarga::route('/{record}/edit'),
        ];
    }
}
