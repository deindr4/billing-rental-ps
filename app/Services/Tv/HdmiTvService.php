<?php

namespace App\Services\Tv;

use App\Exceptions\BillingException;
use App\Models\LogTv;
use App\Models\PerangkatTv;
use App\Models\User;

/**
 * Pilih / paksa input HDMI yang dipakai TV (1 TV bisa berisi beberapa konsol, mis. HDMI 1 PS3, HDMI 3 PS5).
 * Pilihan disimpan di server (dipakai setiap sesi dimulai) dan TV yang sedang terbuka langsung pindah (APK >= 0.6.1).
 * Tarif tidak berubah — tetap mengikuti unit.
 */
final class HdmiTvService
{
    public function __construct(private TvRemoteService $remote) {}

    /** @param string $lewat operator | admin | mulai_sesi */
    public function pindah(PerangkatTv $perangkat, string $inputId, User $user, string $lewat = 'operator'): string
    {
        if ($perangkat->status !== PerangkatTv::STATUS_AKTIF) {
            throw new BillingException('TV sudah dicabut.');
        }

        if (! isset($perangkat->daftarInput()[$inputId])) {
            throw new BillingException('Input HDMI ini tidak dilaporkan TV. Buka Admin → Perangkat TV → Diagnostik untuk memperbarui daftar input.');
        }

        $label = $perangkat->labelHdmi($inputId);
        $berubah = $perangkat->input_hdmi !== $inputId;

        $perangkat->update(['input_hdmi' => $inputId, 'input_hdmi_label' => $label]);

        if ($berubah) {
            LogTv::catat($perangkat, 'input_hdmi', ['label' => $label, 'lewat' => $lewat], $user);
        }

        // TV yang sedang terbuka (main / bypass) langsung pindah; yang terkunci cukup menyimpan pilihan
        $this->remote->kirim($perangkat, 'pindah_hdmi', $user, ['id' => $inputId, 'label' => $label]);
        NotifikasiTv::perangkat($perangkat, 'input_hdmi');

        return $label;
    }

    /** Simpan nama konsol per input (dari admin). @param array<string, string|null> $nama input id => nama */
    public function namai(PerangkatTv $perangkat, array $nama): void
    {
        $daftar = $perangkat->daftarInput();
        $bersih = [];

        foreach ($nama as $id => $n) {
            $n = mb_substr(trim((string) $n), 0, 30);
            if (isset($daftar[$id]) && $n !== '') {
                $bersih[$id] = $n;
            }
        }

        $perangkat->hdmi_nama = $bersih ?: null;

        if ($perangkat->input_hdmi) {
            $perangkat->input_hdmi_label = $perangkat->labelHdmi($perangkat->input_hdmi);
        }

        $perangkat->save();
        NotifikasiTv::perangkat($perangkat, 'input_hdmi');
    }
}
