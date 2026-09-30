<?php

namespace App\Filament\Resources\Produk;

use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\Produk\Pages\CreateProduk;
use App\Filament\Resources\Produk\Pages\EditProduk;
use App\Filament\Resources\Produk\Pages\ListProduk;
use App\Models\Produk;
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
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class ProdukResource extends Resource
{
    use ButuhIzin;

    protected static ?string $model = Produk::class;

    protected static ?string $slug = 'produk';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|UnitEnum|null $navigationGroup = 'F&B';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Produk';

    protected static ?string $pluralModelLabel = 'Produk';

    protected static ?string $recordTitleAttribute = 'nama';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('nama')
                        ->required()
                        ->maxLength(100),
                    Select::make('kategori_produk_id')
                        ->label('Kategori')
                        ->relationship('kategori', 'nama')
                        ->preload()
                        ->createOptionForm([
                            TextInput::make('nama')->required()->maxLength(50),
                        ]),
                    TextInput::make('harga_jual')
                        ->label('Harga jual')
                        ->prefix('Rp')
                        ->mask(RawJs::make("\$money(\$input, ',', '.', 0)"))
                        ->stripCharacters('.')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('satuan')
                        ->default('pcs')
                        ->maxLength(20)
                        ->required(),
                    TextInput::make('kode')
                        ->maxLength(30)
                        ->helperText('Untuk cari cepat di POS, misal MI01')
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('tenant_id', app(Tenancy::class)->tenantId())),
                    TextInput::make('barcode')
                        ->maxLength(50),
                    Toggle::make('lacak_stok')
                        ->label('Lacak stok')
                        ->helperText('Matikan untuk produk tanpa stok, misal jasa')
                        ->default(true)
                        ->live()
                        ->inline(false),
                    TextInput::make('stok_minimum')
                        ->label('Stok minimum (peringatan)')
                        ->numeric()
                        ->minValue(0)
                        ->default(5)
                        ->visible(fn ($get) => (bool) $get('lacak_stok')),
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
                TextColumn::make('kode')->searchable()->placeholder('-'),
                TextColumn::make('kategori.nama')->label('Kategori')->placeholder('-'),
                TextColumn::make('harga_jual')
                    ->label('Harga')
                    ->formatStateUsing(fn ($state) => 'Rp '.number_format((int) $state, 0, ',', '.'))
                    ->sortable(),
                TextColumn::make('stok.qty')
                    ->label('Stok')
                    ->placeholder('0'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('urutan')
            ->filters([
                SelectFilter::make('kategori_produk_id')
                    ->label('Kategori')
                    ->relationship('kategori', 'nama'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProduk::route('/'),
            'create' => CreateProduk::route('/create'),
            'edit' => EditProduk::route('/{record}/edit'),
        ];
    }
}
