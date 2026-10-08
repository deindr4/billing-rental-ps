<?php

namespace App\Filament\Resources\Aksesori;

use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\Aksesori\Pages\CreateAksesori;
use App\Filament\Resources\Aksesori\Pages\EditAksesori;
use App\Filament\Resources\Aksesori\Pages\ListAksesori;
use App\Models\Aksesori;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Aksesori yang disewakan di lokasi (per cabang): harga flat per sesi atau per jam, jumlah stok */
class AksesoriResource extends Resource
{
    use ButuhIzin;

    protected static ?string $model = Aksesori::class;

    protected static ?string $slug = 'aksesori';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string|UnitEnum|null $navigationGroup = 'Rental';

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'Aksesori sewa';

    protected static ?string $pluralModelLabel = 'Aksesori sewa';

    protected static ?string $recordTitleAttribute = 'nama';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->description('Disewa di lokasi bersama sesi unit (tidak dibawa keluar). Dipilih kasir saat Mulai Rental atau Kelola Sesi.')
                ->columns(2)
                ->schema([
                    TextInput::make('nama')
                        ->required()
                        ->maxLength(60)
                        ->placeholder('Stik tambahan'),
                    TextInput::make('harga')
                        ->label('Harga')
                        ->prefix('Rp')
                        ->required()
                        ->mask(RawJs::make('$money($input, \',\', \'.\', 0)'))
                        ->stripCharacters('.')
                        ->numeric()
                        ->minValue(0),
                    Radio::make('satuan')
                        ->label('Dihitung')
                        ->options(Aksesori::SATUAN)
                        ->descriptions([
                            Aksesori::SATUAN_SESI => 'Sekali bayar berapa pun lama mainnya.',
                            Aksesori::SATUAN_JAM => 'Mengikuti lama sewa, dibulatkan seperti open billing (blok, toleransi, minimal).',
                        ])
                        ->default(Aksesori::SATUAN_SESI)
                        ->required(),
                    TextInput::make('stok')
                        ->label('Jumlah yang dimiliki')
                        ->helperText('Kasir tidak bisa menyewakan melebihi jumlah ini.')
                        ->numeric()
                        ->minValue(0)
                        ->default(1)
                        ->required(),
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
                TextColumn::make('harga')->label('Harga')
                    ->formatStateUsing(fn (Aksesori $record) => $record->labelHarga()),
                TextColumn::make('stok')->label('Dipakai / stok')
                    ->formatStateUsing(fn (Aksesori $record) => $record->dipakai().' / '.$record->stok),
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
            'index' => ListAksesori::route('/'),
            'create' => CreateAksesori::route('/create'),
            'edit' => EditAksesori::route('/{record}/edit'),
        ];
    }
}
