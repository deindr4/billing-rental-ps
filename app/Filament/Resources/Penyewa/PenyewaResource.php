<?php

namespace App\Filament\Resources\Penyewa;

use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\Penyewa\Pages\EditPenyewa;
use App\Filament\Resources\Penyewa\Pages\ListPenyewa;
use App\Models\Penyewa;
use App\Support\Koordinat;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Data penyewa Playbox: identitas, alamat & koordinat, foto/KTP (privat), riwayat, daftar hitam */
class PenyewaResource extends Resource
{
    use ButuhIzin;

    protected static ?string $model = Penyewa::class;

    protected static ?string $slug = 'penyewa';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static string|UnitEnum|null $navigationGroup = 'Sewa Playbox';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Penyewa';

    protected static ?string $pluralModelLabel = 'Penyewa';

    protected static ?string $recordTitleAttribute = 'nama';

    public static function canCreate(): bool
    {
        return false; // penyewa dibuat saat sewa di aplikasi kasir (foto & KTP diambil di sana)
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.penyewa.ringkas')->columnSpanFull(),

            Section::make('Data penyewa')
                ->columns(2)
                ->schema([
                    TextInput::make('nama')->required()->maxLength(100),
                    TextInput::make('telepon')->label('Nomor HP / WA')->tel()->required()->regex('/^\+?\d{9,15}$/')
                        ->extraInputAttributes(['inputmode' => 'numeric'])->validationMessages(['regex' => 'Nomor HP 9–15 angka.']),
                    TextInput::make('nik')->label('NIK (KTP)')->length(16)->regex('/^\d{16}$/')
                        ->extraInputAttributes(['inputmode' => 'numeric', 'maxlength' => 16])
                        ->validationMessages(['regex' => 'NIK harus 16 angka.', 'size' => 'NIK harus 16 angka.'])->helperText('16 angka · disimpan terenkripsi.'),
                    Select::make('jenis_tempat')->label('Tinggal di')->options(Penyewa::JENIS_TEMPAT)->required(),
                    Textarea::make('alamat')->rows(2)->columnSpanFull(),
                    TextInput::make('koordinat')
                        ->label('Koordinat / link Google Maps')
                        ->helperText('Tempel link share Google Maps atau "lat, lng".')
                        ->dehydrated(false)
                        ->afterStateHydrated(fn ($component, ?Penyewa $record) => $component->state($record?->lat ? "{$record->lat}, {$record->lng}" : null))
                        ->rule(fn () => function (string $attribute, $value, \Closure $gagal) {
                            if (filled($value) && ! Koordinat::urai($value)) {
                                $gagal('Koordinat / link Google Maps tidak dikenali.');
                            }
                        })
                        ->columnSpanFull(),
                    Textarea::make('catatan')->rows(2)->columnSpanFull(),
                ]),

            Section::make('Daftar hitam')
                ->description('Penyewa bermasalah (telat parah, merusak, tidak mengembalikan). Kasir mendapat peringatan saat menyewakan lagi.')
                ->schema([
                    Toggle::make('daftar_hitam')->label('Masuk daftar hitam')->live(),
                    Textarea::make('alasan_daftar_hitam')->label('Alasan')->rows(2)->required(fn ($get) => (bool) $get('daftar_hitam')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nama')->searchable()->sortable()->description(fn (Penyewa $r) => Penyewa::JENIS_TEMPAT[$r->jenis_tempat] ?? null),
                TextColumn::make('telepon')->label('HP')->searchable(),
                TextColumn::make('riwayat')->label('Riwayat')->state(function (Penyewa $r) {
                    $h = $r->riwayat();

                    return "{$h['sewa']} sewa".($h['telat'] ? " · {$h['telat']} telat" : '').($h['kerusakan'] ? ' · rusak Rp'.number_format($h['kerusakan'], 0, ',', '.') : '');
                }),
                IconColumn::make('daftar_hitam')->label('Daftar hitam')->boolean()
                    ->trueIcon('heroicon-o-no-symbol')->trueColor('danger')->falseIcon('heroicon-o-check-circle')->falseColor('success'),
            ])
            ->filters([
                TernaryFilter::make('daftar_hitam')->label('Daftar hitam'),
            ])
            ->defaultSort('nama')
            ->recordActions([
                Action::make('maps')->label('Maps')->icon('heroicon-o-map-pin')->color('gray')
                    ->url(fn (Penyewa $r) => $r->urlMaps())->openUrlInNewTab()->visible(fn (Penyewa $r) => $r->urlMaps() !== null),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPenyewa::route('/'),
            'edit' => EditPenyewa::route('/{record}/edit'),
        ];
    }
}
