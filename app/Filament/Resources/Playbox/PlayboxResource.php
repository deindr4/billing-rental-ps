<?php

namespace App\Filament\Resources\Playbox;

use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\Playbox\Pages\CreatePlaybox;
use App\Filament\Resources\Playbox\Pages\EditPlaybox;
use App\Filament\Resources\Playbox\Pages\ListPlaybox;
use App\Models\Playbox;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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

/** PlayStation sewa bawa pulang: kelengkapan (harga ganti), tarif per jam/hari/minggu/bulan, denda telat */
class PlayboxResource extends Resource
{
    use ButuhIzin;

    protected static ?string $model = Playbox::class;

    protected static ?string $slug = 'playbox';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|UnitEnum|null $navigationGroup = 'Sewa Playbox';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Playbox';

    protected static ?string $pluralModelLabel = 'Playbox';

    protected static ?string $recordTitleAttribute = 'nama';

    private static function uang(TextInput $input): TextInput
    {
        return $input->prefix('Rp')->mask(RawJs::make("\$money(\$input, ',', '.', 0)"))->stripCharacters('.')->numeric()->minValue(0)->default(0);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Unit')
                ->columns(2)
                ->schema([
                    TextInput::make('kode')->required()->maxLength(20)->placeholder('BOX-01'),
                    TextInput::make('nama')->required()->maxLength(80)->placeholder('PS4 Slim + 2 stik'),
                    TextInput::make('nomor_seri')->label('Nomor seri konsol')->maxLength(60),
                    Select::make('status')->options(Playbox::STATUS)->default('tersedia')->required()
                        ->helperText('Disewa diatur otomatis oleh transaksi sewa.'),
                    TextInput::make('urutan')->numeric()->minValue(0)->default(0),
                    Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
                    Textarea::make('catatan')->rows(2)->columnSpanFull(),
                ]),

            Section::make('Kelengkapan')
                ->description('Menjadi checklist saat unit keluar & kembali. Harga ganti dipakai sebagai biaya bila rusak / hilang (bisa diubah saat pengembalian).')
                ->schema([
                    Repeater::make('kelengkapan')
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('nama')->required()->maxLength(60)->placeholder('Stik DualShock'),
                            TextInput::make('jumlah')->numeric()->minValue(1)->default(1)->required(),
                            self::uang(TextInput::make('harga_ganti'))->label('Harga ganti'),
                        ])
                        ->columns(3)
                        ->default([
                            ['nama' => 'Konsol PlayStation', 'jumlah' => 1, 'harga_ganti' => 0],
                            ['nama' => 'Stik', 'jumlah' => 2, 'harga_ganti' => 0],
                            ['nama' => 'Kabel HDMI', 'jumlah' => 1, 'harga_ganti' => 0],
                            ['nama' => 'Kabel power', 'jumlah' => 1, 'harga_ganti' => 0],
                            ['nama' => 'Tas / box', 'jumlah' => 1, 'harga_ganti' => 0],
                        ])
                        ->addActionLabel('Tambah kelengkapan'),
                ]),

            Section::make('Tarif & denda')
                ->description('Isi 0 bila tidak disewakan dengan satuan itu. Mingguan / bulanan boleh lebih murah (diskon).')
                ->columns(4)
                ->schema([
                    self::uang(TextInput::make('harga_jam'))->label('Per jam'),
                    self::uang(TextInput::make('harga_hari'))->label('Per hari'),
                    self::uang(TextInput::make('harga_minggu'))->label('Per minggu'),
                    self::uang(TextInput::make('harga_bulan'))->label('Per bulan'),
                    self::uang(TextInput::make('denda_jam'))->label('Denda telat / jam'),
                    self::uang(TextInput::make('denda_hari'))->label('Denda telat / hari')
                        ->helperText('Dipakai bila denda per jam 0.'),
                    self::uang(TextInput::make('deposit_saran'))->label('Saran uang deposit'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $rp = fn (int $n) => $n > 0 ? 'Rp'.number_format($n, 0, ',', '.') : '–';

        return $table
            ->columns([
                TextColumn::make('kode')->searchable()->sortable(),
                TextColumn::make('nama')->searchable()->description(fn (Playbox $r) => $r->nomor_seri),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => Playbox::STATUS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'tersedia' => 'success', 'disewa' => 'warning', default => 'gray' }),
                TextColumn::make('harga_hari')->label('Tarif')
                    ->formatStateUsing(fn (Playbox $r) => collect($r->tarif())->map(fn ($h, $s) => $rp($h).'/'.$s)->implode(' · ') ?: '–'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('urutan')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlaybox::route('/'),
            'create' => CreatePlaybox::route('/create'),
            'edit' => EditPlaybox::route('/{record}/edit'),
        ];
    }
}
