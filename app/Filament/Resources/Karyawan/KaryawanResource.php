<?php

namespace App\Filament\Resources\Karyawan;

use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\Karyawan\Pages\CreateKaryawan;
use App\Filament\Resources\Karyawan\Pages\EditKaryawan;
use App\Filament\Resources\Karyawan\Pages\ListKaryawan;
use App\Models\Cabang;
use App\Models\Karyawan;
use App\Models\User;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Data karyawan: profil, kepegawaian, rekening (terenkripsi), gaji & bonus, PIN absen */
class KaryawanResource extends Resource
{
    use ButuhIzin;

    protected static ?string $model = Karyawan::class;

    protected static ?string $slug = 'karyawan';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|UnitEnum|null $navigationGroup = 'Karyawan';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Karyawan';

    protected static ?string $pluralModelLabel = 'Karyawan';

    protected static ?string $recordTitleAttribute = 'nama';

    protected static function izinAkses(): string
    {
        return 'karyawan.kelola';
    }

    private static function uang(TextInput $input): TextInput
    {
        return $input->prefix('Rp')->mask(RawJs::make("\$money(\$input, ',', '.', 0)"))->stripCharacters('.')->numeric()->minValue(0)->default(0);
    }

    public static function form(Schema $schema): Schema
    {
        $tenantId = fn () => app(Tenancy::class)->tenantId();

        return $schema->components([
            Section::make('Profil')
                ->columns(2)
                ->schema([
                    TextInput::make('nama')->required()->maxLength(100),
                    TextInput::make('jabatan')->maxLength(50)->placeholder('Kasir / OB / Teknisi'),
                    TextInput::make('telepon')->tel()->maxLength(30),
                    Select::make('cabang_id')
                        ->label('Cabang utama')
                        ->options(fn () => Cabang::query()->where('tenant_id', $tenantId())->orderBy('kode')->pluck('nama', 'id'))
                        ->placeholder('Semua cabang'),
                    Select::make('user_id')
                        ->label('Akun login')
                        ->helperText('Tautkan bila karyawan memakai aplikasi (kasir, supervisor). Kosongkan untuk OB / cleaning.')
                        ->options(fn (?Karyawan $record) => User::query()
                            ->where('tenant_id', $tenantId())
                            ->whereNotIn('id', Karyawan::query()->whereNotNull('user_id')->when($record, fn ($q) => $q->whereKeyNot($record->id))->pluck('user_id'))
                            ->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->placeholder('Tanpa akun login'),
                    FileUpload::make('foto')
                        ->image()
                        ->avatar()
                        ->disk('public')
                        ->directory(fn () => 'tenants/'.$tenantId().'/karyawan')
                        ->maxSize(4096)
                        ->helperText('Dikompres otomatis ke WebP. Dipakai untuk mencocokkan foto absen.'),
                    Toggle::make('is_active')->label('Aktif bekerja')->default(true)->inline(false),
                ]),

            Section::make('Data pribadi')
                ->description('NIK & nomor rekening disimpan terenkripsi.')
                ->columns(2)
                ->collapsed()
                ->schema([
                    TextInput::make('nik')->label('NIK (KTP)')->maxLength(20),
                    DatePicker::make('tanggal_lahir')->label('Tanggal lahir'),
                    Textarea::make('alamat')->rows(2)->columnSpanFull(),
                    TextInput::make('kontak_darurat_nama')->label('Kontak darurat (nama / hubungan)')->maxLength(100),
                    TextInput::make('kontak_darurat_telepon')->label('Telepon kontak darurat')->tel()->maxLength(30),
                ]),

            Section::make('Kepegawaian & rekening')
                ->columns(2)
                ->collapsed()
                ->schema([
                    DatePicker::make('tanggal_masuk')->label('Tanggal masuk'),
                    DatePicker::make('tanggal_keluar')->label('Tanggal keluar')->helperText('Diisi saat berhenti bekerja.'),
                    TextInput::make('bank')->maxLength(50)->placeholder('BCA / BRI / DANA'),
                    TextInput::make('no_rekening')->label('Nomor rekening')->maxLength(40),
                    TextInput::make('atas_nama')->label('Atas nama')->maxLength(100),
                    Textarea::make('catatan')->rows(2)->columnSpanFull(),
                ]),

            Section::make('Gaji & bonus')
                ->description('Komponen bisa digabung: pokok bulanan + upah per shift + upah per jam + bonus target omzet. Dipakai saat rekap gaji.')
                ->columns(2)
                ->collapsed()
                ->schema([
                    Select::make('periode_gaji')->label('Periode gaji')->options(Karyawan::PERIODE)->default('bulanan')->required(),
                    self::uang(TextInput::make('gaji_pokok'))->label('Gaji pokok per bulan'),
                    self::uang(TextInput::make('upah_shift'))->label('Upah per shift / hari hadir'),
                    self::uang(TextInput::make('upah_jam'))->label('Upah per jam kerja'),
                    self::uang(TextInput::make('bonus_target'))->label('Target omzet per shift')
                        ->helperText('Omzet shift yang dipegang karyawan ini. 0 = tanpa bonus.'),
                    Select::make('bonus_jenis')->label('Jenis bonus')->options(Karyawan::BONUS_JENIS)->default('nominal')->required(),
                    TextInput::make('bonus_nilai')->label('Nilai bonus')->numeric()->minValue(0)->default(0)
                        ->helperText('Nominal: rupiah per shift yang mencapai target. Persen: % dari omzet di atas target.'),
                ]),

            Section::make('PIN absen')
                ->description('Untuk absen di aplikasi kasir. Karyawan dengan akun login memakai PIN akunnya.')
                ->schema([
                    TextInput::make('pin_baru')
                        ->label(fn (?Karyawan $record) => $record?->pin ? 'PIN baru (kosongkan jika tidak diganti)' : 'PIN')
                        ->password()->revealable()
                        ->regex('/^\d{4,6}$/')
                        ->validationMessages(['regex' => 'PIN harus 4 sampai 6 angka.'])
                        ->dehydrated(false),
                ])
                ->hidden(fn ($get) => filled($get('user_id'))),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user:id,name', 'cabang:id,kode']))
            ->columns([
                ImageColumn::make('foto')->disk('public')->circular()->label('')->defaultImageUrl(null),
                TextColumn::make('nama')->searchable()->sortable()->description(fn (Karyawan $r) => $r->jabatan),
                TextColumn::make('cabang.kode')->label('Cabang')->badge()->placeholder('Semua'),
                TextColumn::make('user.name')->label('Akun login')->placeholder('—'),
                TextColumn::make('telepon')->placeholder('-')->toggleable(),
                TextColumn::make('tanggal_masuk')->label('Masa kerja')->formatStateUsing(fn (Karyawan $r) => $r->masaKerja())->placeholder('-'),
                TextColumn::make('periode_gaji')->label('Gaji')->formatStateUsing(fn (?string $state) => Karyawan::PERIODE[$state] ?? $state)->toggleable(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Aktif bekerja')->default(true),
            ])
            ->defaultSort('nama')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKaryawan::route('/'),
            'create' => CreateKaryawan::route('/create'),
            'edit' => EditKaryawan::route('/{record}/edit'),
        ];
    }
}
