<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Database\Factories\UserFactory;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use Diaudit, HasFactory, HasRoles, HasUuids, Notifiable;

    /** Login terakhir tidak perlu masuk audit log */
    protected array $auditAbaikan = ['last_login_at'];

    // pin sengaja tidak mass-assignable: diisi lewat forceFill(['pin' => Hash::make(...)])
    protected $fillable = [
        'tenant_id',
        'name',
        'username',
        'email',
        'phone',
        'password',
        'is_super_admin',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'pin',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function cabang(): BelongsToMany
    {
        return $this->belongsToMany(Cabang::class, 'cabang_user')->withTimestamps();
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    /** Sudah punya PIN persetujuan (supervisor/owner) */
    public function punyaPin(): bool
    {
        return ! empty($this->pin);
    }

    /** Panel admin: super admin, atau pengguna aktif dengan izin admin.akses */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        // Dipanggil Filament sebelum middleware SetTenancy: aktifkan tim (tenant) Spatie dulu,
        // kalau tidak izin role tenant terbaca kosong dan owner mendapat 403.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant_id);

        return $this->can('admin.akses');
    }

    /** Boleh membuka panel admin (untuk tombol "Admin" di aplikasi kasir) */
    public function bisaBukaAdmin(): bool
    {
        return $this->canAccessPanel(Filament::getPanel('admin'));
    }

    /**
     * Cabang yang boleh dibuka user ini.
     * Owner: semua cabang aktif di tenant-nya. Lainnya: cabang yang ditugaskan.
     */
    public function cabangTersedia(): Builder
    {
        if ($this->hasRole('Owner')) {
            return Cabang::query()
                ->where('tenant_id', $this->tenant_id)
                ->where('is_active', true)
                ->orderBy('kode');
        }

        return Cabang::query()
            ->whereIn('id', $this->cabang()->select('cabang.id'))
            ->where('is_active', true)
            ->orderBy('kode');
    }
}
