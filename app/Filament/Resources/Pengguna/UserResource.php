<?php

namespace App\Filament\Resources\Pengguna;

use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\Pengguna\Pages\CreateUser;
use App\Filament\Resources\Pengguna\Pages\EditUser;
use App\Filament\Resources\Pengguna\Pages\ListUsers;
use App\Models\Role;
use App\Models\User;
use App\Services\PinService;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

class UserResource extends Resource
{
    use ButuhIzin;

    protected static ?string $model = User::class;

    protected static ?string $slug = 'pengguna';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Pengguna';

    protected static ?string $pluralModelLabel = 'Pengguna';

    protected static ?string $recordTitleAttribute = 'name';

    protected static function izinAkses(): string
    {
        return 'admin.pengguna';
    }

    /** Hanya pengguna di tenant aktif */
    public static function getEloquentQuery(): Builder
    {
        $tenantId = app(Tenancy::class)->tenantId();

        return parent::getEloquentQuery()
            ->when($tenantId, fn (Builder $q) => $q->where('tenant_id', $tenantId))
            ->with(['roles', 'cabang']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Akun')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nama')
                        ->required()
                        ->maxLength(100),
                    TextInput::make('username')
                        ->maxLength(50)
                        ->alphaDash()
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('tenant_id', app(Tenancy::class)->tenantId())),
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(150)
                        ->unique(ignoreRecord: true),
                    TextInput::make('phone')
                        ->label('No. HP')
                        ->tel()
                        ->maxLength(30),
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        ->required(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->helperText(fn (string $operation) => $operation === 'edit' ? 'Kosongkan jika tidak diganti.' : null),
                    Toggle::make('is_active')
                        ->label('Aktif')
                        ->helperText('Nonaktifkan untuk memblokir login. Pengguna tidak dihapus.')
                        ->default(true)
                        ->inline(false),
                ]),

            Section::make('Akses')
                ->columns(2)
                ->schema([
                    Select::make('role')
                        ->label('Role')
                        ->options(fn () => Role::query()
                            ->where('tenant_id', app(Tenancy::class)->tenantId())
                            ->orderBy('name')
                            ->pluck('name', 'name'))
                        ->required()
                        ->dehydrated(false)
                        ->afterStateHydrated(fn ($component, $record) => $component->state($record?->roles->first()?->name)),
                    CheckboxList::make('cabang')
                        ->label('Cabang')
                        ->relationship('cabang', 'nama', modifyQueryUsing: fn (Builder $query) => $query->where('tenant_id', app(Tenancy::class)->tenantId()))
                        ->helperText('Owner otomatis bisa membuka semua cabang.')
                        ->columns(2),
                ]),

            Section::make('PIN persetujuan')
                ->description('Dipakai untuk menyetujui aksi sensitif: batal transaksi, tambah waktu gratis, dan lainnya sesuai izin role.')
                ->schema([
                    TextInput::make('pin_baru')
                        ->label(fn ($record) => $record?->punyaPin() ? 'PIN baru (kosongkan jika tidak diganti)' : 'PIN')
                        ->password()
                        ->revealable()
                        ->regex('/^\d{4,6}$/')
                        ->validationMessages(['regex' => 'PIN harus 4 sampai 6 angka.'])
                        ->dehydrated(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('roles.name')->label('Role')->badge(),
                TextColumn::make('cabang.kode')->label('Cabang')->badge()->placeholder('-'),
                IconColumn::make('pin')
                    ->label('PIN')
                    ->boolean()
                    ->getStateUsing(fn (User $record) => $record->punyaPin()),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
                TextColumn::make('last_login_at')->label('Login terakhir')->since()->placeholder('-'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /** Simpan role & PIN setelah create/edit (field ini tidak disimpan otomatis) */
    public static function simpanRoleDanPin(User $user, array $data): void
    {
        $tenantId = app(Tenancy::class)->tenantId() ?? $user->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        if (filled($data['role'] ?? null)) {
            $user->syncRoles([$data['role']]);
        }

        if (filled($data['pin_baru'] ?? null)) {
            $user->forceFill(['pin' => Hash::make($data['pin_baru'])])->save();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** PIN tidak boleh sama dengan pengguna lain di tenant ini */
    public static function pastikanPinUnik(?string $pin, ?string $kecualiUserId = null): void
    {
        if (blank($pin)) {
            return;
        }

        $tenantId = app(Tenancy::class)->tenantId();

        if ($tenantId && ! app(PinService::class)->tersedia($pin, $tenantId, $kecualiUserId)) {
            throw ValidationException::withMessages([
                'data.pin_baru' => 'PIN sudah dipakai pengguna lain. Gunakan PIN berbeda.',
            ]);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
