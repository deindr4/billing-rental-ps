<?php

namespace App\Services\Tv;

use App\Exceptions\BillingException;
use App\Models\LogTv;
use App\Models\PairingTv;
use App\Models\PerangkatTv;
use App\Models\Unit;
use App\Models\User;
use App\Support\WakeOnLan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alur pairing:
 * 1. TV (belum terdaftar) meminta kode -> tampil 6 angka di layar TV
 * 2. Admin memasukkan kode & memilih unit di panel
 * 3. TV menanyakan hasil memakai kunci rahasianya -> menerima token (sekali saja)
 */
final class PairingTvService
{
    public const MASA_BERLAKU_MENIT = 10;

    /** @return array{pairing: PairingTv, kunci: string} */
    public function mulai(array $info): array
    {
        // Bersihkan kode lama
        PairingTv::query()->where('kedaluwarsa_pada', '<', now()->subDay())->delete();

        $kunci = Str::random(48);

        for ($coba = 0; ; $coba++) {
            $kode = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

            if (! PairingTv::query()->where('kode', $kode)->exists()) {
                break;
            }

            if ($coba > 20) {
                throw new BillingException('Gagal membuat kode pairing, coba lagi.');
            }
        }

        $pairing = PairingTv::create([
            'kode' => $kode,
            'kunci_hash' => hash('sha256', $kunci),
            'android_id' => $info['android_id'],
            'info' => array_filter([
                'merek' => $info['merek'] ?? null,
                'model' => $info['model'] ?? null,
                'versi_android' => $info['versi_android'] ?? null,
                'versi_app' => $info['versi_app'] ?? null,
                'jenis' => ($info['jenis'] ?? null) === PerangkatTv::JENIS_PC ? PerangkatTv::JENIS_PC : null,
                'mac' => WakeOnLan::rapikanMac($info['mac'] ?? null),
            ]),
            'kedaluwarsa_pada' => now()->addMinutes(self::MASA_BERLAKU_MENIT),
        ]);

        return ['pairing' => $pairing, 'kunci' => $kunci];
    }

    /**
     * Dipanggil TV berkala. Return: ['status' => menunggu|kedaluwarsa|berhasil, ...kredensial]
     */
    public function hasil(string $kunci): ?array
    {
        return DB::transaction(function () use ($kunci) {
            $pairing = PairingTv::query()->where('kunci_hash', hash('sha256', $kunci))->lockForUpdate()->first();

            if (! $pairing) {
                return null;
            }

            if ($pairing->kredensial) {
                $kredensial = $pairing->kredensial;
                $pairing->delete(); // kredensial hanya bisa diambil sekali

                return ['status' => 'berhasil'] + $kredensial;
            }

            if ($pairing->kedaluwarsa_pada->isPast()) {
                return ['status' => 'kedaluwarsa'];
            }

            return ['status' => 'menunggu', 'kedaluwarsa_detik' => (int) now()->diffInSeconds($pairing->kedaluwarsa_pada)];
        });
    }

    /** Dipanggil admin: pasangkan TV (lewat kode di layar) ke unit */
    public function pasangkan(string $kode, Unit $unit, User $user): PerangkatTv
    {
        $kode = preg_replace('/\D/', '', $kode);

        return DB::transaction(function () use ($kode, $unit, $user) {
            $pairing = PairingTv::query()->berlaku()->where('kode', $kode)->lockForUpdate()->first();

            if (! $pairing || $pairing->perangkat_id) {
                throw new BillingException('Kode tidak ditemukan atau sudah kedaluwarsa. Minta TV menampilkan kode baru.');
            }

            $info = $pairing->info ?? [];
            $jenis = ($info['jenis'] ?? null) === PerangkatTv::JENIS_PC ? PerangkatTv::JENIS_PC : PerangkatTv::JENIS_TV;

            if (($jenis === PerangkatTv::JENIS_PC) !== $unit->isPc()) {
                throw new BillingException($jenis === PerangkatTv::JENIS_PC
                    ? 'Kode ini dari PC, tapi unit yang dipilih bukan unit PC. Pilih unit bertipe PC.'
                    : 'Kode ini dari TV, tapi unit yang dipilih adalah unit PC.');
            }

            // Satu unit hanya satu TV aktif: TV lama dicabut
            PerangkatTv::withoutGlobalScopes()
                ->where('unit_id', $unit->id)
                ->aktif()
                ->where('android_id', '!=', $pairing->android_id)
                ->get()
                ->each(fn (PerangkatTv $lama) => $this->cabut($lama, $user, 'Diganti TV lain'));

            // TV yang sama dipasangkan ulang: pakai data lama
            $perangkat = PerangkatTv::withoutGlobalScopes()
                ->where('tenant_id', $unit->tenant_id)
                ->where('android_id', $pairing->android_id)
                ->first() ?? new PerangkatTv(['tenant_id' => $unit->tenant_id, 'android_id' => $pairing->android_id]);

            $token = Str::random(64);
            $rahasia = Str::random(32);

            $perangkat->fill([
                'cabang_id' => $unit->cabang_id,
                'unit_id' => $unit->id,
                'jenis' => $jenis,
                'mac' => $info['mac'] ?? $perangkat->mac,
                'merek' => $info['merek'] ?? $perangkat->merek,
                'model' => $info['model'] ?? $perangkat->model,
                'versi_android' => $info['versi_android'] ?? $perangkat->versi_android,
                'versi_app' => $info['versi_app'] ?? $perangkat->versi_app,
                'status' => PerangkatTv::STATUS_AKTIF,
                'token_hash' => hash('sha256', $token),
                'rahasia_offline' => $rahasia,
                'bypass_sampai' => null,
                'dipasangkan_pada' => now(),
                'dipasangkan_oleh' => $user->id,
            ])->save();

            $unit->update([
                'mode_kontrol' => Unit::MODE_TV_AGENT,
                'tipe_perangkat' => $jenis === PerangkatTv::JENIS_PC ? 'windows_pc' : 'android_tv',
            ]);

            $pairing->update([
                'perangkat_id' => $perangkat->id,
                'kredensial' => [
                    'token' => $token,
                    'rahasia_offline' => $rahasia,
                    'perangkat_id' => $perangkat->id,
                ],
                // beri waktu TV mengambil token
                'kedaluwarsa_pada' => now()->addMinutes(self::MASA_BERLAKU_MENIT),
            ]);

            LogTv::catat($perangkat, 'pairing', ['unit' => $unit->nama, 'kode' => $kode], $user);

            return $perangkat;
        });
    }

    public function cabut(PerangkatTv $perangkat, User $user, ?string $alasan = null): void
    {
        $perangkat->update([
            'status' => PerangkatTv::STATUS_DICABUT,
            'token_hash' => null,
            'bypass_sampai' => null,
        ]);

        LogTv::catat($perangkat, 'dicabut', array_filter(['alasan' => $alasan]), $user);

        // TV akan menerima 401 saat menyegarkan status, lalu kembali ke layar pairing
        NotifikasiTv::perangkat($perangkat, 'dicabut');
    }
}
