<?php

namespace App\Filament\Resources\Absensi;

use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\Absensi\Pages\EditAbsensi;
use App\Filament\Resources\Absensi\Pages\ListAbsensi;
use App\Models\Absensi;
use App\Models\Karyawan;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Riwayat absensi (PIN + foto selfie) per cabang; koreksi jam oleh owner wajib beralasan (log aktivitas) */
class AbsensiResource extends Resource
{
    use ButuhIzin;

    protected static ?string $model = Absensi::class;

    protected static ?string $slug = 'absensi';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-finger-print';

    protected static string|UnitEnum|null $navigationGroup = 'Karyawan';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'Absensi';

    protected static ?string $pluralModelLabel = 'Absensi';

    protected static function izinAkses(): string
    {
        return 'karyawan.kelola';
    }

    public static function canCreate(): bool
    {
        return false; // absen hanya dari aplikasi kasir (PIN + foto)
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Koreksi absensi')
                ->description('Mis. lupa absen pulang. Terlambat & lama kerja dihitung ulang dari jadwal. Perubahan tercatat di log aktivitas.')
                ->columns(2)
                ->schema([
                    DateTimePicker::make('masuk_pada')->label('Masuk')->seconds(false)->required(),
                    DateTimePicker::make('pulang_pada')->label('Pulang')->seconds(false)->after('masuk_pada'),
                    Textarea::make('catatan')->label('Alasan koreksi')->required()->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['karyawan:id,nama', 'template:id,nama,jam_mulai,jam_selesai']))
            ->columns([
                ImageColumn::make('foto_masuk')->label('Masuk')->disk('public')->circular(),
                ImageColumn::make('foto_pulang')->label('Pulang')->disk('public')->circular(),
                TextColumn::make('karyawan.nama')->label('Karyawan')->searchable(),
                TextColumn::make('tanggal')->date('d/m/Y')->sortable(),
                TextColumn::make('masuk_pada')->label('Jam')
                    ->formatStateUsing(fn (Absensi $r) => $r->masuk_pada->format('H:i').' – '.($r->pulang_pada?->format('H:i') ?? '…'))
                    ->description(fn (Absensi $r) => $r->template ? $r->template->nama.' '.$r->template->label() : 'Di luar jadwal'),
                TextColumn::make('terlambat_menit')->label('Terlambat')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? "{$state} m" : '-')
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('menit_kerja')->label('Kerja')->formatStateUsing(fn (?int $state) => Absensi::formatMenit($state))->placeholder('belum pulang'),
                TextColumn::make('catatan')->limit(30)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('karyawan_id')->label('Karyawan')
                    ->options(fn () => Karyawan::query()->orderBy('nama')->pluck('nama', 'id')),
                Filter::make('periode')
                    ->schema([DatePicker::make('dari'), DatePicker::make('sampai')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['dari'] ?? null, fn ($q, $d) => $q->whereDate('tanggal', '>=', $d))
                        ->when($data['sampai'] ?? null, fn ($q, $d) => $q->whereDate('tanggal', '<=', $d))),
                Filter::make('terlambat')->label('Hanya yang terlambat')->query(fn (Builder $query) => $query->where('terlambat_menit', '>', 0)),
            ])
            ->defaultSort('masuk_pada', 'desc')
            ->recordActions([EditAction::make()->label('Koreksi')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAbsensi::route('/'),
            'edit' => EditAbsensi::route('/{record}/edit'),
        ];
    }
}
