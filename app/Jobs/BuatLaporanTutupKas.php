<?php

namespace App\Jobs;

use App\Models\Shift;
use App\Services\Notifikasi\LaporanTutupKas;
use App\Services\Notifikasi\PengaturanNotifikasi;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\Permission\PermissionRegistrar;

/**
 * Setelah tutup kas: susun teks + PDF, lalu antrekan pengiriman ke Telegram & WhatsApp.
 */
class BuatLaporanTutupKas implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $shiftId) {}

    public function handle(LaporanTutupKas $laporan, Tenancy $tenancy): void
    {
        $shift = Shift::withoutGlobalScopes()->findOrFail($this->shiftId);

        $tenancy->set($shift->tenant_id, $shift->cabang_id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($shift->tenant_id);

        $setelan = PengaturanNotifikasi::untuk($shift->cabang_id);

        if (! $setelan->kirimSaatTutupKas() || (! $setelan->telegramAktif() && ! $setelan->waAktif())) {
            return;
        }

        $data = $laporan->data($shift);
        $teks = $laporan->teks($data);
        $pdf = $setelan->lampirkanPdf() ? $laporan->pdf($data) : null;
        $namaFile = $pdf ? $laporan->namaFile($data) : null;

        foreach (['telegram' => $setelan->telegramAktif(), 'whatsapp' => $setelan->waAktif()] as $saluran => $aktif) {
            if ($aktif) {
                KirimNotifikasi::antrekan($saluran, $shift->tenant_id, $shift->cabang_id, 'tutup_kas', $teks, $pdf, $namaFile, $shift);
            }
        }
    }
}
