<?php

namespace App\Filament\Resources\Penggajian;

use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\Penggajian\Pages\EditPenggajian;
use App\Filament\Resources\Penggajian\Pages\ListPenggajian;
use App\Models\Penggajian;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Rekap gaji per karyawan per periode: draft → disetujui → dibayar (pengeluaran gaji) */
class PenggajianResource extends Resource
{
    use ButuhIzin;

    protected static ?string $model = Penggajian::class;

    protected static ?string $slug = 'penggajian';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Karyawan';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Rekap gaji';

    protected static ?string $pluralModelLabel = 'Rekap gaji';

    protected static ?string $recordTitleAttribute = 'nomor';

    protected static function izinAkses(): string
    {
        return 'karyawan.kelola';
    }

    public static function canCreate(): bool
    {
        return false; // dibuat lewat tombol "Buat rekap" (periode & karyawan)
    }

    public static function form(Schema $schema): Schema
    {
        $kunci = fn (?Penggajian $record) => ! $record?->bisaDiubah();

        return $schema->components([
            View::make('filament.penggajian.rincian')->columnSpanFull(),

            Section::make('Potongan selisih kas')
                ->description('Usulan dari kas kurang saat tutup kas / serah terima di shift yang dipegang karyawan. Hanya yang dicentang yang dipotong.')
                ->schema([
                    CheckboxList::make('potongan_setuju')
                        ->hiddenLabel()
                        ->options(fn (?Penggajian $record) => collect($record?->rincian['usulan_potongan'] ?? [])
                            ->mapWithKeys(fn ($u) => [$u['kunci'] => "{$u['tanggal']} · {$u['shift']} · {$u['jenis']} · Rp ".number_format($u['nilai'], 0, ',', '.')])->all())
                        ->disabled($kunci),
                ])
                ->visible(fn (?Penggajian $record) => ! empty($record?->rincian['usulan_potongan'])),

            Section::make('Penyesuaian')
                ->description('Tambahan (+) atau potongan lain (−): uang makan, lembur, kasbon, dll.')
                ->schema([
                    Repeater::make('penyesuaian_daftar')
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('keterangan')->required()->maxLength(100),
                            TextInput::make('nilai')->label('Nilai (Rp, minus = potongan)')->numeric()->required(),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Tambah penyesuaian')
                        ->disabled($kunci),
                    Textarea::make('catatan')->rows(2)->disabled($kunci),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('karyawan:id,nama'))
            ->columns([
                TextColumn::make('nomor')->searchable(),
                TextColumn::make('karyawan.nama')->label('Karyawan')->searchable(),
                TextColumn::make('periode_mulai')->label('Periode')->formatStateUsing(fn (Penggajian $r) => $r->labelPeriode()),
                TextColumn::make('total')->money('IDR', locale: 'id')->sortable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => Penggajian::STATUS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'dibayar' => 'success', 'disetujui' => 'info', 'batal' => 'gray', default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(Penggajian::STATUS),
            ])
            ->defaultSort('periode_mulai', 'desc')
            ->recordActions([EditAction::make()->label('Buka')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPenggajian::route('/'),
            'edit' => EditPenggajian::route('/{record}/edit'),
        ];
    }
}
