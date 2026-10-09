<?php

namespace App\Filament\Resources\TemplateShift;

use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\TemplateShift\Pages\CreateTemplateShift;
use App\Filament\Resources\TemplateShift\Pages\EditTemplateShift;
use App\Filament\Resources\TemplateShift\Pages\ListTemplateShift;
use App\Models\TemplateShift;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Jam kerja shift karyawan (Pagi, Siang, Malam) — dipakai jadwal mingguan & hitungan terlambat absensi */
class TemplateShiftResource extends Resource
{
    use ButuhIzin;

    protected static ?string $model = TemplateShift::class;

    protected static ?string $slug = 'template-shift';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'Karyawan';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Jam shift';

    protected static ?string $pluralModelLabel = 'Jam shift';

    protected static ?string $recordTitleAttribute = 'nama';

    protected static function izinAkses(): string
    {
        return 'karyawan.kelola';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->description('Jam selesai lebih kecil dari jam mulai = lewat tengah malam (mis. 17:00–02:00).')
                ->columns(2)
                ->schema([
                    TextInput::make('nama')->required()->maxLength(40)->placeholder('Pagi'),
                    TextInput::make('toleransi_menit')->label('Toleransi terlambat')->numeric()->minValue(0)->maxValue(120)
                        ->default(10)->suffix('menit')->required(),
                    TimePicker::make('jam_mulai')->label('Jam mulai')->seconds(false)->required(),
                    TimePicker::make('jam_selesai')->label('Jam selesai')->seconds(false)->required(),
                    TextInput::make('urutan')->numeric()->minValue(0)->default(0),
                    Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nama')->searchable(),
                TextColumn::make('jam_mulai')->label('Jam')->formatStateUsing(fn (TemplateShift $record) => $record->label()),
                TextColumn::make('durasi')->label('Lama')->state(fn (TemplateShift $record) => intdiv($record->menit(), 60).' j '.($record->menit() % 60).' m'),
                TextColumn::make('toleransi_menit')->label('Toleransi')->suffix(' menit'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('urutan')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTemplateShift::route('/'),
            'create' => CreateTemplateShift::route('/create'),
            'edit' => EditTemplateShift::route('/{record}/edit'),
        ];
    }
}
