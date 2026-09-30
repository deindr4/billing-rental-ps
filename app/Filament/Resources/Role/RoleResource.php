<?php

namespace App\Filament\Resources\Role;

use App\Filament\Concerns\ButuhIzin;
use App\Filament\Resources\Role\Pages\CreateRole;
use App\Filament\Resources\Role\Pages\EditRole;
use App\Filament\Resources\Role\Pages\ListRoles;
use App\Models\Role;
use App\Support\HakAkses;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class RoleResource extends Resource
{
    use ButuhIzin;

    protected static ?string $model = Role::class;

    protected static ?string $slug = 'role';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Role & Hak Akses';

    protected static ?string $pluralModelLabel = 'Role & Hak Akses';

    protected static ?string $recordTitleAttribute = 'name';

    protected static function izinAkses(): string
    {
        return 'admin.pengguna';
    }

    /** Hanya role milik tenant aktif */
    public static function getEloquentQuery(): Builder
    {
        $tenantId = app(Tenancy::class)->tenantId();

        return parent::getEloquentQuery()
            ->when($tenantId, fn (Builder $q) => $q->where('tenant_id', $tenantId));
    }

    /** Role Owner selalu punya semua izin, tidak bisa diubah */
    public static function canEdit(Model $record): bool
    {
        return $record->name !== 'Owner' && parent::canEdit($record);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    TextInput::make('name')
                        ->label('Nama role')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule
                            ->where('tenant_id', app(Tenancy::class)->tenantId())
                            ->where('guard_name', 'web')),
                ]),

            Section::make('Hak akses')
                ->description('Centang aksi yang boleh dilakukan role ini.')
                ->schema([
                    CheckboxList::make('permissions')
                        ->hiddenLabel()
                        ->relationship('permissions', 'name', modifyQueryUsing: fn (Builder $query) => $query->orderBy('name'))
                        ->getOptionLabelFromRecordUsing(fn ($record) => HakAkses::label($record->name))
                        ->bulkToggleable()
                        ->columns(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Role')->searchable(),
                TextColumn::make('permissions_count')->label('Jumlah izin')->counts('permissions'),
                TextColumn::make('keterangan')
                    ->label('')
                    ->getStateUsing(fn (Role $record) => $record->name === 'Owner' ? 'Semua izin, tidak bisa diubah' : null)
                    ->color('gray'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
