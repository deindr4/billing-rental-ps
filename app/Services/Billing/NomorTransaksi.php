<?php

namespace App\Services\Billing;

use App\Models\Cabang;
use App\Models\NomorUrut;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Nomor transaksi unik: {PREFIX}-{KODE_CABANG}-{L|V}-{YYYYMMDD}-{0001}
 * Contoh: BIL-DGH1-L-20260928-0001
 */
final class NomorTransaksi
{
    public function buat(string $prefix, Cabang $cabang): string
    {
        $sumber = config('app.mode') === 'cloud' ? 'V' : 'L';
        $tanggal = now()->toDateString();

        return DB::transaction(function () use ($prefix, $cabang, $sumber, $tanggal) {
            NomorUrut::query()->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $cabang->tenant_id,
                'cabang_id' => $cabang->id,
                'prefix' => $prefix,
                'sumber' => $sumber,
                'tanggal' => $tanggal,
                'terakhir' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = NomorUrut::query()
                ->where('cabang_id', $cabang->id)
                ->where('prefix', $prefix)
                ->where('sumber', $sumber)
                ->whereDate('tanggal', $tanggal)
                ->lockForUpdate()
                ->firstOrFail();

            $row->terakhir = $row->terakhir + 1;
            $row->save();

            return sprintf('%s-%s-%s-%s-%04d', $prefix, $cabang->kode, $sumber, str_replace('-', '', $tanggal), $row->terakhir);
        });
    }
}
