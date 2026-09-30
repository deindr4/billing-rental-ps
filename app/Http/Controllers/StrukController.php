<?php

namespace App\Http\Controllers;

use App\Models\Transaksi;
use App\Services\Struk\StrukService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Cetak struk thermal (via RawBT) & nota A4.
 * Transaksi dicari lewat query biasa (bukan route binding) supaya scope tenant & cabang aktif berlaku.
 */
class StrukController extends Controller
{
    public function __construct(private StrukService $struk) {}

    private function transaksi(string $id): Transaksi
    {
        $user = auth()->user();

        abort_unless($user->can('transaksi.lihat') || $user->can('pembayaran.terima'), 403);

        return Transaksi::query()->findOrFail($id);
    }

    /** Dibuka dari tablet Android: diarahkan ke aplikasi RawBT yang meneruskan ke printer Bluetooth */
    public function thermal(string $id): RedirectResponse
    {
        return redirect()->away($this->struk->urlRawbt($this->transaksi($id)));
    }

    public function nota(string $id): View
    {
        return view('struk.nota', $this->struk->data($this->transaksi($id)));
    }
}
