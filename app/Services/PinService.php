<?php

namespace App\Services;

use App\Exceptions\BillingException;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Persetujuan aksi sensitif dengan PIN.
 * PIN dicocokkan ke pengguna aktif di tenant yang sama, lalu dicek izinnya.
 */
final class PinService
{
    public const MAKS_PERCOBAAN = 5;

    public const BLOKIR_DETIK = 300;

    /** Kembalikan user pemilik PIN yang menyetujui. */
    public function setujui(?string $pin, string $izin, string $tenantId): User
    {
        $kunci = 'pin:'.$tenantId.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($kunci, self::MAKS_PERCOBAAN)) {
            throw new BillingException('Terlalu banyak PIN salah. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.');
        }

        $pin = trim((string) $pin);

        if (! preg_match('/^\d{4,6}$/', $pin)) {
            RateLimiter::hit($kunci, self::BLOKIR_DETIK);

            throw new BillingException('PIN harus 4 sampai 6 angka.');
        }

        $kandidat = User::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('pin')
            ->get();

        foreach ($kandidat as $user) {
            if (Hash::check($pin, $user->pin)) {
                if (! $user->can($izin)) {
                    // Ikut dihitung & tanpa nama: jangan jadi cara menebak PIN milik siapa
                    RateLimiter::hit($kunci, self::BLOKIR_DETIK);
                    Audit::catat('pin_gagal', "PIN tanpa izin untuk {$izin}", null, ['izin' => $izin], tenantId: $tenantId);

                    throw new BillingException('PIN ini tidak punya izin untuk menyetujui aksi ini.');
                }

                RateLimiter::clear($kunci);
                Audit::catat('pin_disetujui', "PIN {$user->name} menyetujui {$izin}", null, ['izin' => $izin], tenantId: $tenantId, userId: auth()->id() ?? $user->id);

                return $user;
            }
        }

        RateLimiter::hit($kunci, self::BLOKIR_DETIK);
        Audit::catat('pin_gagal', "PIN salah untuk {$izin}", null, ['izin' => $izin], tenantId: $tenantId);

        throw new BillingException('PIN salah.');
    }

    /** Pastikan PIN belum dipakai pengguna lain di tenant yang sama. */
    public function tersedia(string $pin, string $tenantId, ?string $kecualiUserId = null): bool
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('pin')
            ->when($kecualiUserId, fn ($q) => $q->whereKeyNot($kecualiUserId))
            ->get(['id', 'pin'])
            ->doesntContain(fn (User $u) => Hash::check($pin, $u->pin));
    }
}
