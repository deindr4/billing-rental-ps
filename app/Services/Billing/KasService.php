<?php

namespace App\Services\Billing;

use App\Models\KasMutasi;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class KasService
{
    /** Catat mutasi laci kas. Positif = masuk laci, negatif = keluar laci. */
    public function catat(
        Shift $shift,
        string $jenis,
        int $jumlah,
        User $user,
        ?Model $sumber = null,
        ?string $keterangan = null,
    ): KasMutasi {
        return KasMutasi::create([
            'tenant_id' => $shift->tenant_id,
            'cabang_id' => $shift->cabang_id,
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'jenis' => $jenis,
            'jumlah' => $jumlah,
            'sumber_type' => $sumber?->getMorphClass(),
            'sumber_id' => $sumber?->getKey(),
            'keterangan' => $keterangan,
        ]);
    }

    public function saldo(Shift $shift): int
    {
        return (int) KasMutasi::withoutGlobalScopes()->where('shift_id', $shift->id)->sum('jumlah');
    }
}
