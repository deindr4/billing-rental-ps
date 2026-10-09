<?php

namespace App\Livewire\Operator;

use App\Models\Shift;
use App\Models\User;
use App\Services\Billing\ShiftService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Laporan satu shift (setelah serah terima / tutup kas): kas, setoran, yang ditinggal, selisih,
 * penerima, serta sesi & tagihan yang diteruskan. Bisa dicetak; tombol keluar untuk ganti kasir.
 */
#[Layout('layouts.operator')]
#[Title('Laporan Shift')]
class LaporanShift extends Component
{
    public Shift $shift;

    public function mount(string $id): void
    {
        $this->shift = Shift::query()->findOrFail($id);
        $user = auth()->user();

        abort_unless(
            in_array($user->id, [$this->shift->user_id, $this->shift->ditutup_oleh, $this->shift->diserahkan_ke], true)
                || $user->can('laporan.lihat') || $user->can('shift.bantu'),
            403
        );
    }

    public function render()
    {
        $nama = User::query()->whereIn('id', array_filter([$this->shift->user_id, $this->shift->ditutup_oleh, $this->shift->diserahkan_ke]))
            ->pluck('name', 'id');

        return view('livewire.operator.laporan-shift', [
            'r' => app(ShiftService::class)->ringkasan($this->shift),
            'nama' => $nama,
            'berikut' => $this->shift->shift_berikut_id ? Shift::query()->find($this->shift->shift_berikut_id) : null,
            'potret' => $this->shift->serah_terima ?? ['sesi_main' => [], 'belum_bayar' => []],
            // Pemegang lama tanpa izin membantu: laci sudah bukan miliknya → tawarkan keluar
            'perluKeluar' => ! app(ShiftService::class)->aktif(auth()->user(), $this->shift->cabang_id),
        ]);
    }
}
