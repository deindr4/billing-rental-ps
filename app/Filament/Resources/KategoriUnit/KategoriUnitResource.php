<?php

namespace App\Filament\Resources\KategoriUnit;

use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\KategoriUnit\Pages\CreateKategoriUnit;
use App\Filament\Resources\KategoriUnit\Pages\EditKategoriUnit;
use App\Filament\Resources\KategoriUnit\Pages\ListKategoriUnit;
use App\Models\KategoriUnit;
use App\Support\Tenancy;
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
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class KategoriUnitResource extends Resource
{
    use ButuhIzin;

    protected static ?string $model = KategoriUnit::class;

    protected static ?string $slug = 'kategori-unit';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|UnitEnum|null $navigationGroup = 'Rental';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'Kategori Unit';

    protected static ?string $pluralModelLabel = 'Kategori Unit';

    protected static ?string $recordTitleAttribute = 'nama';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('kode')
                        ->required()
                        ->maxLength(20)
                        ->placeholder('VIP')
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('tenant_id', app(Tenancy::class)->tenantId())),
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
                TextColumn::make('kode')->searchable()->sortable(),
                TextColumn::make('nama')->searchable(),
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
            'index' => ListKategoriUnit::route('/'),
            'create' => CreateKategoriUnit::route('/create'),
            'edit' => EditKategoriUnit::route('/{record}/edit'),
        ];
    }
}
