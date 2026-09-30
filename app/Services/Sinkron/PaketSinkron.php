<?php

namespace App\Services\Sinkron;

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menyusun paket perubahan dari baris sync_antrean: isi baris terkini diambil dari tabelnya.
 *
 * Satu item paket:
 *   ['tabel' => 'units', 'aksi' => 'upsert', 'id' => uuid, 'data' => [...kolom]]
 *   ['tabel' => 'units', 'aksi' => 'hapus',  'id' => uuid]
 *   ['tabel' => 'roles', 'aksi' => 'upsert', 'id' => uuid, 'data' => [...], 'izin' => ['rental.kelola', ...]]
 *   ['tabel' => 'cabang_user', 'aksi' => 'pivot', 'id' => user_id, 'rows' => [...]]
 */
final class PaketSinkron
{
    /**
     * @param  Collection<int, object>  $antrean  baris sync_antrean (urut id)
     * @return array<int, array>
     */
    public function bangun(Collection $antrean): array
    {
        // Satu entri per (tabel, id): perubahan terakhir yang dipakai
        $unik = $antrean->filter(fn ($a) => DaftarTabel::boleh($a->tabel))
            ->keyBy(fn ($a) => $a->tabel.'|'.$a->row_id);

        $paket = [];

        foreach ($unik->groupBy('tabel') as $tabel => $baris) {
            $ids = $baris->pluck('row_id')->all();

            $paket = array_merge($paket, match (true) {
                $tabel === 'roles' => $this->roles($ids),
                isset(DaftarTabel::PIVOT[$tabel]) => $this->pivot($tabel, $ids),
                default => $this->baris($tabel, $ids),
            });
        }

        return $paket;
    }

    /** Baris biasa: yang masih ada = upsert, yang sudah tidak ada = hapus */
    private function baris(string $tabel, array $ids): array
    {
        $ada = DB::table($tabel)->whereIn('id', $ids)->get()->keyBy('id');

        return array_map(fn ($id) => $ada->has($id)
            ? ['tabel' => $tabel, 'aksi' => 'upsert', 'id' => $id, 'data' => (array) $ada[$id]]
            : ['tabel' => $tabel, 'aksi' => 'hapus', 'id' => $id], $ids);
    }

    /** Role + daftar nama izinnya (ID izin berbeda di tiap server) */
    private function roles(array $ids): array
    {
        $ada = DB::table('roles')->whereIn('id', $ids)->get()->keyBy('id');
        $izin = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->whereIn('role_has_permissions.role_id', $ids)
            ->get(['role_has_permissions.role_id', 'permissions.name'])
            ->groupBy('role_id');

        return array_map(fn ($id) => $ada->has($id)
            ? ['tabel' => 'roles', 'aksi' => 'upsert', 'id' => $id, 'data' => (array) $ada[$id],
                'izin' => ($izin[$id] ?? collect())->pluck('name')->values()->all()]
            : ['tabel' => 'roles', 'aksi' => 'hapus', 'id' => $id], $ids);
    }

    /** Satu set pivot per induk. Role/izin dikirim sebagai NAMA. */
    private function pivot(string $tabel, array $induk): array
    {
        [$kolom] = DaftarTabel::PIVOT[$tabel];
        $rows = DB::table($tabel)->whereIn($kolom, $induk)->get();

        if ($tabel === 'model_has_roles') {
            $nama = DB::table('roles')->whereIn('id', $rows->pluck('role_id'))->pluck('name', 'id');
            $rows = $rows->map(fn ($r) => ['role' => $nama[$r->role_id] ?? null] + (array) $r)->filter(fn ($r) => $r['role']);
        } elseif ($tabel === 'model_has_permissions') {
            $nama = DB::table('permissions')->whereIn('id', $rows->pluck('permission_id'))->pluck('name', 'id');
            $rows = $rows->map(fn ($r) => ['permission' => $nama[$r->permission_id] ?? null] + (array) $r)->filter(fn ($r) => $r['permission']);
        } else {
            $rows = $rows->map(fn ($r) => (array) $r);
        }

        $perInduk = $rows->groupBy(fn ($r) => $r[$kolom]);

        return array_map(fn ($id) => [
            'tabel' => $tabel,
            'aksi' => 'pivot',
            'id' => $id,
            'rows' => ($perInduk[$id] ?? collect())->values()->all(),
        ], $induk);
    }
}
