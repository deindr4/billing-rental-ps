<?php

namespace App\Filament\Resources\Iklan;

use App\Filament\Resources\Iklan\Pages\CreateIklan;
use App\Filament\Resources\Iklan\Pages\EditIklan;
use App\Filament\Resources\Iklan\Pages\ListIklan;
use App\Models\Cabang;
use App\Models\Iklan;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Iklan bergambar di bagian bawah billboard publik. Lewat masa tayang: berhenti tampil & terhapus otomatis.
 */
class IklanResource extends Resource
{
    protected static ?string $model = Iklan::class;

    protected static ?string $slug = 'iklan';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static string|UnitEnum|null $navigationGroup = 'Rental';

    protected static ?string $modelLabel = 'Iklan billboard';

    protected static ?string $pluralModelLabel = 'Iklan billboard';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('admin.master');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Gambar')
                ->schema([
                    Placeholder::make('pratinjau')
                        ->label('Gambar sekarang')
                        ->visibleOn('edit')
                        ->content(fn (?Iklan $record) => $record ? new HtmlString('<img src="'.e($record->urlGambar()).'" style="max-height:220px;border-radius:10px">') : '-'),
                    FileUpload::make('berkas')
                        ->label(fn (string $operation) => $operation === 'edit' ? 'Ganti gambar (opsional)' : 'Gambar iklan')
                        ->image()
                        ->disk('local')
                        ->directory('iklan-sementara')
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                        ->maxSize(5120)
                        ->required(fn (string $operation) => $operation === 'create')
                        ->helperText('PNG/JPG/WebP maks. 5 MB. Disarankan lebar (misal 1200×600 atau 1080×1080). Otomatis dikompres.'),
                ]),

            Section::make('Detail & masa tayang')
                ->columns(2)
                ->schema([
                    TextInput::make('judul')->required()->maxLength(120)->placeholder('Promo Warung Sebelah'),
                    TextInput::make('pengiklan')->label('Pengiklan')->maxLength(120)->placeholder('Nama / usaha pemasang iklan'),
                    TextInput::make('tautan')->label('Tautan saat diklik (opsional)')->url()->maxLength(300)
                        ->placeholder('https://wa.me/62812... atau https://instagram.com/...')->columnSpanFull(),
                    DateTimePicker::make('mulai_pada')->label('Mulai tayang')->seconds(false)->required()->default(now()->startOfHour()),
                    DateTimePicker::make('selesai_pada')->label('Selesai tayang')->seconds(false)->required()
                        ->default(now()->startOfHour()->addDays(7))->after('mulai_pada')
                        ->helperText('Setelah waktu ini iklan tidak tampil lagi dan terhapus otomatis.'),
                    Select::make('cabang_id')->label('Tampil di cabang')
                        ->options(fn () => Cabang::query()->where('tenant_id', app(Tenancy::class)->tenantId())->orderBy('kode')->pluck('nama', 'id'))
                        ->placeholder('Semua cabang'),
                    TextInput::make('urutan')->numeric()->minValue(0)->default(0),
                    Toggle::make('is_active')->label('Aktif')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $warna = ['tayang' => 'success', 'terjadwal' => 'info', 'berakhir' => 'danger', 'nonaktif' => 'gray'];

        return $table
            ->defaultSort('mulai_pada', 'desc')
            ->columns([
                ImageColumn::make('gambar_url')->label('')->state(fn (Iklan $r) => $r->urlGambar())->height(48),
                TextColumn::make('judul')->searchable()->description(fn (Iklan $r) => $r->pengiklan),
                TextColumn::make('status')->badge()
                    ->state(fn (Iklan $r) => $r->status())
                    ->color(fn (string $state) => $warna[$state] ?? 'gray')
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextColumn::make('mulai_pada')->label('Tayang')->dateTime('d/m/y H:i')
                    ->description(fn (Iklan $r) => 's/d '.$r->selesai_pada->format('d/m/y H:i')),
                TextColumn::make('sisa')->label('Sisa')
                    ->state(fn (Iklan $r) => $r->selesai_pada->isPast() ? '-' : $r->selesai_pada->diffForHumans(null, true)),
                TextColumn::make('cabang.nama')->label('Cabang')->placeholder('Semua'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIklan::route('/'),
            'create' => CreateIklan::route('/create'),
            'edit' => EditIklan::route('/{record}/edit'),
        ];
    }
}
