<?php

namespace App\Observers;

use App\Models\Sesi;
use App\Models\Unit;
use App\Services\Tv\NotifikasiTv;
use Illuminate\Database\Eloquent\Model;

/**
 * Setiap perubahan sesi/unit yang terlihat di TV memicu sinyal segarkan.
 * Semua perubahan sesi menaikkan versi_tagihan lewat save(), jadi cukup dengar event "saved".
 */
class TvObserver
{
    /** Kolom unit yang memengaruhi tampilan TV */
    private const KOLOM_UNIT = ['status', 'nama', 'posisi_timer', 'durasi_bypass_menit', 'mode_kontrol', 'is_active', 'wallpaper'];

    public function saved(Model $model): void
    {
        if ($model instanceof Sesi) {
            NotifikasiTv::unit($model->unit_id, 'sesi');

            // Pindah unit: TV unit lama juga harus kembali terkunci
            if ($model->wasChanged('unit_id')) {
                NotifikasiTv::unit($model->getOriginal('unit_id'), 'sesi');
            }

            return;
        }

        if ($model instanceof Unit && ($model->wasRecentlyCreated || $model->wasChanged(self::KOLOM_UNIT))) {
            NotifikasiTv::unit($model->id, 'unit');
        }
    }
}
