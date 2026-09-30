<?php

namespace App\Filament\Resources\AuditLog;

use App\Filament\Resources\AuditLog\Pages\ListAuditLog;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Log aktivitas (audit): hanya baca. Perubahan data master & kejadian penting.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $slug = 'audit-log';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 90;

    protected static ?string $modelLabel = 'Log aktivitas';

    protected static ?string $pluralModelLabel = 'Log aktivitas';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->can('audit.lihat'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')->label('Waktu')->dateTime('d/m/Y H:i:s'),
                    TextEntry::make('aksi')->label('Aksi')->formatStateUsing(fn ($state, AuditLog $record) => $record->labelAksi()),
                    TextEntry::make('user.name')->label('Pengguna')->placeholder('Sistem'),
                    TextEntry::make('cabang.nama')->label('Cabang')->placeholder('-'),
                    TextEntry::make('keterangan')->columnSpanFull(),
                    TextEntry::make('subjek_type')->label('Data')->formatStateUsing(fn ($state, AuditLog $record) => $record->labelSubjek())->placeholder('-'),
                    TextEntry::make('ip')->label('IP')->placeholder('-'),
                    TextEntry::make('data')->label('Rincian')->columnSpanFull()
                        ->state(fn (AuditLog $record) => self::rincian($record)),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('d/m/y H:i')->sortable(),
                TextColumn::make('aksi')->label('Aksi')->badge()
                    ->formatStateUsing(fn ($state, AuditLog $record) => $record->labelAksi())
                    ->color(fn (AuditLog $record) => $record->anomali ? 'warning' : ($record->aksi === 'dihapus' ? 'danger' : 'gray')),
                TextColumn::make('keterangan')->wrap()->searchable()->limit(90),
                TextColumn::make('user.name')->label('Pengguna')->placeholder('Sistem')->searchable(),
                TextColumn::make('cabang.nama')->label('Cabang')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('anomali')->boolean()->label('Anomali')->toggleable(),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('anomali')->label('Anomali'),
                SelectFilter::make('aksi')->options(AuditLog::LABEL_AKSI)->multiple(),
                SelectFilter::make('user_id')->label('Pengguna')
                    ->options(fn () => User::query()->where('tenant_id', app(Tenancy::class)->tenantId())->orderBy('name')->pluck('name', 'id')),
                Filter::make('tanggal')
                    ->schema([
                        DatePicker::make('dari'),
                        DatePicker::make('sampai'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['dari'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
                        ->when($data['sampai'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /** Tabel lama -> baru untuk perubahan data, atau daftar isian untuk kejadian */
    public static function rincian(AuditLog $record): HtmlString
    {
        $data = $record->data ?? [];

        if ($data === []) {
            return new HtmlString('-');
        }

        $e = fn ($v) => e(is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_UNICODE));

        if (isset($data['lama']) || isset($data['baru'])) {
            $kolom = array_unique(array_merge(array_keys($data['lama'] ?? []), array_keys($data['baru'] ?? [])));
            $baris = collect($kolom)->map(fn ($k) => '<tr><td style="padding:2px 8px 2px 0;opacity:.7">'.e($k).'</td>'
                .'<td style="padding:2px 8px;color:#f87171">'.(array_key_exists($k, $data['lama'] ?? []) ? $e($data['lama'][$k]) : '').'</td>'
                .'<td style="padding:2px 8px;color:#4ade80">'.(array_key_exists($k, $data['baru'] ?? []) ? $e($data['baru'][$k]) : '').'</td></tr>')->implode('');

            return new HtmlString('<table style="font-size:12px"><tr><th></th><th style="text-align:left;padding:2px 8px">Lama</th><th style="text-align:left;padding:2px 8px">Baru</th></tr>'.$baris.'</table>');
        }

        return new HtmlString(collect($data)->map(fn ($v, $k) => '<div><span style="opacity:.7">'.e($k).':</span> '.$e($v).'</div>')->implode(''));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLog::route('/'),
        ];
    }
}
