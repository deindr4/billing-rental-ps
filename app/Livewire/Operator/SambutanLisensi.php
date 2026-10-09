<?php

namespace App\Livewire\Operator;

use App\Models\Pengaturan;
use Livewire\Component;

/**
 * Popup Lisensi MIT + kontak Telegram & grup WhatsApp, tampil SEKALI setelah pasang baru di Windows.
 * Installer (pasang.ps1 → pasang:awal --sambut-lisensi) menyalakan penanda; begitu popup tampil, penanda dimatikan
 * sehingga tidak muncul lagi (juga tidak di update). Dipasang di layout operator.
 */
class SambutanLisensi extends Component
{
    public const KUNCI = 'lisensi.sambut';

    public bool $buka = false;

    public function mount(): void
    {
        if (config('app.mode') !== 'local' || Pengaturan::ambil(self::KUNCI, false) !== true) {
            return;
        }

        // Dianggap sudah dilihat begitu tampil: muat ulang / pindah halaman tidak memunculkannya lagi
        Pengaturan::simpan(self::KUNCI, false);
        $this->buka = true;
    }

    public function render()
    {
        return view('livewire.operator.sambutan-lisensi', [
            'versi' => trim((string) @file_get_contents(base_path('VERSION'))),
        ]);
    }
}
