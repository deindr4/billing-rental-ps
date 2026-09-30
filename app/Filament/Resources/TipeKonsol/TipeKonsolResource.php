<?php

namespace App\Filament\Resources\TipeKonsol;

use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\TipeKonsol\Pages\CreateTipeKonsol;
use App\Filament\Resources\TipeKonsol\Pages\EditTipeKonsol;
use App\Filament\Resources\TipeKonsol\Pages\ListTipeKonsol;
use App\Models\TipeKonsol;
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

class TipeKonsolResource extends Resource
{
    use ButuhIzin;

    protected static ?string $model = TipeKonsol::class;

    protected static ?string $slug = 'tipe-konsol';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static string|UnitEnum|null $navigationGroup = 'Rental';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Tipe Konsol';

    protected static ?string $pluralModelLabel = 'Tipe Konsol';

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
                        ->placeholder('PS5')
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
            'index' => ListTipeKonsol::route('/'),
            'create' => CreateTipeKonsol::route('/create'),
            'edit' => EditTipeKonsol::route('/{record}/edit'),
        ];
    }
}
