<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Buat super admin platform, atau ganti password super admin yang lupa.
 * Dipakai sekali di server baru (cloud / hasil instal) — super admin tidak bisa dibuat dari panel admin.
 */
class SuperAdmin extends Command
{
    protected $signature = 'superadmin {email? : Email login} {--nama=Super Admin : Nama tampilan} {--password= : Password (kosong = ditanya)}';

    protected $description = 'Buat super admin platform atau reset password-nya';

    public function handle(): int
    {
        $email = strtolower(trim((string) ($this->argument('email') ?: text('Email super admin', required: true, validate: fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'Email tidak valid'))));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email tidak valid.');

            return self::FAILURE;
        }

        $user = User::withoutGlobalScopes()->where('email', $email)->first();

        if ($user && ! $user->isSuperAdmin()) {
            $this->error("{$email} sudah dipakai akun rental (bukan super admin). Pakai email lain.");

            return self::FAILURE;
        }

        $pass = (string) ($this->option('password') ?: password('Password (min. 8 karakter)', required: true, validate: fn ($v) => strlen($v) < 8 ? 'Minimal 8 karakter' : null));

        if (strlen($pass) < 8) {
            $this->error('Password minimal 8 karakter.');

            return self::FAILURE;
        }

        if ($user) {
            $user->forceFill(['password' => Hash::make($pass), 'is_active' => true])->save();
            $this->info("Password super admin {$email} diganti.");

            return self::SUCCESS;
        }

        $user = new User;
        $user->forceFill([
            'tenant_id' => null,
            'name' => (string) $this->option('nama'),
            'email' => $email,
            'password' => Hash::make($pass),
            'is_super_admin' => true,
            'is_active' => true,
        ])->save();

        $this->info("Super admin {$email} dibuat. Login di ".url('/admin'));

        return self::SUCCESS;
    }
}
