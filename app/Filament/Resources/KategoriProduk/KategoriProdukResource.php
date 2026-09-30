<?php

namespace App\Filament\Resources\KategoriProduk;

use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\KategoriProduk\Pages\CreateKategoriProduk;
use App\Filament\Resources\KategoriProduk\Pages\EditKategoriProduk;
use App\Filament\Resources\KategoriProduk\Pages\ListKategoriProduk;
use App\Models\KategoriProduk;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class KategoriProdukResource extends Resource
{
    use ButuhIzin;

    protected static ?string $model = KategoriProduk::class;

    protected static ?string $slug = 'kategori-produk';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|UnitEnum|null $navigationGroup = 'F&B';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Kategori Produk';

    protected static ?string $pluralModelLabel = 'Kategori Produk';

    protected static ?string $recordTitleAttribute = 'nama';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('nama')
                        ->required()
                        ->maxLength(50),
                    TextInput::make('urutan')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),
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
                TextColumn::make('produk_count')->label('Jumlah produk')->counts('produk'),
                TextColumn::make('urutan')->sortable(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('urutan')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKategoriProduk::route('/'),
            'create' => CreateKategoriProduk::route('/create'),
            'edit' => EditKategoriProduk::route('/{record}/edit'),
        ];
    }
}
