<?php

namespace App\Services\Sinkron;

use App\Support\Sinkron\DaftarTabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Menerapkan paket perubahan dari server lain.
 *
 * - Konflik: baris dengan updated_at lebih baru yang menang (yang lebih lama dilewati).
 * - Stok produk & saldo/poin/stamp member dihitung ulang dari buku besar (stok_mutasi, member_mutasi),
 *   jadi penjualan / top up di dua server saat terputus tetap terjumlah semua.
 * - $tenantWajib (di cloud): baris milik tenant lain ditolak.
 * - Lokal: perubahan dari cloud tidak dicatat ulang ke antrean (@sync_lewati).
 *   Cloud: dicatat dengan sumber = server pengirim, supaya tidak dikirim balik ke server itu.
 */
final class TerapkanSinkron
{
    /**
     * Kolom yang tidak pernah diterima lewat sinkron (keamanan): pemegang token sinkron satu tenant
     * tidak boleh menjadikan penggunanya super admin platform. Super admin hanya dibuat lewat
     * `php artisan superadmin` / installer di server itu sendiri.
     */
    private const KOLOM_TERLINDUNG = [
        'users' => ['is_super_admin'],
    ];

    private array $kolomCache = [];

    /** @return array{diterapkan:int, dilewati:int, ditolak:array<int,string>} */
    public function terapkan(array $paket, ?string $tenantWajib = null, ?string $sumber = null): array
    {
        $hasil = ['diterapkan' => 0, 'dilewati' => 0, 'ditolak' => []];
        $stokDisentuh = [];
        $memberDisentuh = [];

        DB::statement($sumber === null ? 'SET @sync_lewati = 1' : 'SET @sync_sumber = ?', $sumber === null ? [] : [$sumber]);
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            DB::transaction(function () use ($paket, $tenantWajib, &$hasil, &$stokDisentuh, &$memberDisentuh) {
                foreach ($paket as $item) {
                    $tabel = (string) ($item['tabel'] ?? '');
                    $id = (string) ($item['id'] ?? '');

                    if (! DaftarTabel::boleh($tabel) || $id === '' || ! Schema::hasTable($tabel)) {
                        $hasil['ditolak'][] = "{$tabel}:{$id} tabel tidak dikenal";

                        continue;
                    }

                    $status = match ($item['aksi'] ?? '') {
                        'upsert' => $tabel === 'roles'
                            ? $this->role($item, $tenantWajib)
                            : $this->upsert($tabel, $item['data'] ?? [], $tenantWajib),
                        'hapus' => $this->hapus($tabel, $id, $tenantWajib),
                        'pivot' => $this->pivot($tabel, $id, $item['rows'] ?? [], $tenantWajib),
                        default => 'ditolak',
                    };

                    if ($status === 'ditolak') {
                        $hasil['ditolak'][] = "{$tabel}:{$id}";

                        continue;
                    }

                    $hasil[$status === 'ok' ? 'diterapkan' : 'dilewati']++;

                    if ($status === 'ok' && $tabel === 'stok_mutasi') {
                        $d = $item['data'];
                        $stokDisentuh[$d['produk_id'].'|'.$d['cabang_id']] = [$d['produk_id'], $d['cabang_id'], $d['tenant_id']];
                    }

                    if ($status === 'ok' && $tabel === 'member_mutasi') {
                        $memberDisentuh[$item['data']['member_id']] = true;
                    }
                }

                $this->hitungUlangStok($stokDisentuh);
                $this->hitungUlangMember(array_keys($memberDisentuh));
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            DB::statement('SET @sync_lewati = NULL, @sync_sumber = NULL');
        }

        return $hasil;
    }

    /** @return 'ok'|'lewat'|'ditolak' */
    private function upsert(string $tabel, array $data, ?string $tenantWajib): string
    {
        $id = $data['id'] ?? null;

        if (! $id || ! $this->tenantCocok($tabel, $data, $tenantWajib)) {
            return 'ditolak';
        }

        // Hanya kolom yang ada di server ini (versi aplikasi bisa beda sedikit), tanpa kolom terlindung
        $data = array_diff_key(
            array_intersect_key($data, array_flip($this->kolom($tabel))),
            array_flip(self::KOLOM_TERLINDUNG[$tabel] ?? []),
        );

        // Super admin di server ini (tanpa tenant) tidak bisa ditimpa / diambil alih lewat sinkron
        if ($tabel === 'users' && DB::table('users')->where('id', $id)->where('is_super_admin', true)->exists()) {
            return 'ditolak';
        }

        if (in_array($tabel, DaftarTabel::HANYA_TAMBAH, true)) {
            return DB::table($tabel)->insertOrIgnore($data) > 0 ? 'ok' : 'lewat';
        }

        $punyaTenant = in_array('tenant_id', $this->kolom($tabel), true);
        $lama = DB::table($tabel)->where('id', $id)->first([
            'id', ...(isset($data['updated_at']) ? ['updated_at'] : []), ...($punyaTenant ? ['tenant_id'] : []),
        ]);

        if ($lama) {
            // Baris yang sudah ada milik tenant lain tidak boleh ditimpa (id sama dari pengirim tenant lain)
            if ($tenantWajib !== null && $tabel !== 'tenants' && $punyaTenant && $lama->tenant_id !== $tenantWajib) {
                return 'ditolak';
            }

            // Data di server ini lebih baru: jangan ditimpa
            if (isset($data['updated_at'], $lama->updated_at) && strcmp((string) $lama->updated_at, (string) $data['updated_at']) > 0) {
                return 'lewat';
            }

            DB::table($tabel)->where('id', $id)->update($data);

            return 'ok';
        }

        // Baris baru tapi bentrok kunci unik (mis. data master yang sama dibuat terpisah di dua server): lewati
        try {
            DB::table($tabel)->insert($data);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                return 'lewat';
            }

            throw $e;
        }

        return 'ok';
    }

    private function hapus(string $tabel, string $id, ?string $tenantWajib): string
    {
        $lama = DB::table($tabel)->where('id', $id)->first();

        if (! $lama) {
            return 'lewat';
        }

        if (! $this->tenantCocok($tabel, (array) $lama, $tenantWajib) || ($tabel === 'users' && ! empty($lama->is_super_admin))) {
            return 'ditolak';
        }

        try {
            DB::table($tabel)->where('id', $id)->delete();
        } catch (Throwable) {
            return 'lewat'; // tabel yang dilindungi trigger larangan hapus
        }

        return 'ok';
    }

    /** Role dicocokkan lewat (tenant, nama); izin disamakan lewat nama */
    private function role(array $item, ?string $tenantWajib): string
    {
        $data = $item['data'] ?? [];

        if (! $this->tenantCocok('roles', $data, $tenantWajib) || empty($data['name'])) {
            return 'ditolak';
        }

        $role = DB::table('roles')->where('tenant_id', $data['tenant_id'])->where('name', $data['name'])
            ->where('guard_name', $data['guard_name'] ?? 'web')->first();

        $roleId = $role->id ?? $data['id'];

        if (! $role) {
            DB::table('roles')->insert(array_intersect_key($data, array_flip($this->kolom('roles'))));
        }

        $izin = DB::table('permissions')->whereIn('name', $item['izin'] ?? [])->pluck('id');

        DB::table('role_has_permissions')->where('role_id', $roleId)->delete();
        DB::table('role_has_permissions')->insert($izin->map(fn ($p) => ['permission_id' => $p, 'role_id' => $roleId])->all());

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return 'ok';
    }

    /** Pivot: ganti seluruh set milik satu induk */
    private function pivot(string $tabel, string $induk, array $rows, ?string $tenantWajib): string
    {
        [$kolom, $tabelInduk] = DaftarTabel::PIVOT[$tabel];

        if ($tenantWajib) {
            $tenant = $tabelInduk
                ? DB::table($tabelInduk)->where('id', $induk)->value('tenant_id')
                : ($rows[0]['tenant_id'] ?? $tenantWajib);

            if ($tenant !== null && $tenant !== $tenantWajib) {
                return 'ditolak';
            }
        }

        $baru = [];

        foreach ($rows as $r) {
            if (($r[$kolom] ?? null) !== $induk) {
                continue;
            }

            if ($tabel === 'model_has_roles') {
                $r['role_id'] = DB::table('roles')->where('tenant_id', $r['tenant_id'])->where('name', $r['role'])->value('id');
                unset($r['role']);

                if (! $r['role_id']) {
                    continue;
                }
            }

            if ($tabel === 'model_has_permissions') {
                $r['permission_id'] = DB::table('permissions')->where('name', $r['permission'])->value('id');
                unset($r['permission']);

                if (! $r['permission_id']) {
                    continue;
                }
            }

            $baru[] = array_intersect_key($r, array_flip($this->kolom($tabel)));
        }

        DB::table($tabel)->where($kolom, $induk)->delete();

        if ($baru !== []) {
            DB::table($tabel)->insert($baru);
        }

        if (Str::startsWith($tabel, 'model_has_')) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return 'ok';
    }

    private function tenantCocok(string $tabel, array $data, ?string $tenantWajib): bool
    {
        if ($tenantWajib === null) {
            return true;
        }

        $tenant = $tabel === 'tenants' ? ($data['id'] ?? null) : ($data['tenant_id'] ?? null);

        return $tenant === $tenantWajib;
    }

    /** qty stok = jumlah semua mutasi stok (penjualan di dua server tetap terhitung) */
    private function hitungUlangStok(array $pasangan): void
    {
        foreach ($pasangan as [$produkId, $cabangId, $tenantId]) {
            $qty = (int) DB::table('stok_mutasi')->where('produk_id', $produkId)->where('cabang_id', $cabangId)->sum('qty');

            $ada = DB::table('produk_stok')->where('produk_id', $produkId)->where('cabang_id', $cabangId)->exists();

            if ($ada) {
                // updated_at tidak disentuh supaya tidak mengalahkan perubahan server lain (aturan "terbaru menang")
                DB::table('produk_stok')->where('produk_id', $produkId)->where('cabang_id', $cabangId)
                    ->where('qty', '!=', $qty)->update(['qty' => $qty]);
            } else {
                DB::table('produk_stok')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'cabang_id' => $cabangId, 'produk_id' => $produkId,
                    'qty' => $qty, 'hpp_rata' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /** Saldo, poin, stamp & total belanja member = jumlah buku besar member_mutasi */
    private function hitungUlangMember(array $memberIds): void
    {
        if ($memberIds === []) {
            return;
        }

        $jumlah = DB::table('member_mutasi')->whereIn('member_id', $memberIds)
            ->selectRaw('member_id, akun, SUM(jumlah) as total')->groupBy('member_id', 'akun')->get()->groupBy('member_id');

        foreach ($memberIds as $id) {
            $a = ($jumlah[$id] ?? collect())->pluck('total', 'akun');

            DB::table('members')->where('id', $id)->update([
                'saldo' => (int) ($a['saldo'] ?? 0),
                'poin' => (int) ($a['poin'] ?? 0),
                'stamp' => (int) ($a['stamp'] ?? 0),
                'total_belanja' => max(0, (int) ($a['belanja'] ?? 0)),
            ]);
        }
    }

    private function kolom(string $tabel): array
    {
        return $this->kolomCache[$tabel] ??= Schema::getColumnListing($tabel);
    }
}
