<?php

namespace App\Livewire\Operator;

use App\Models\Notifikasi;
use App\Services\Notifikasi\Lonceng;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Lonceng di pojok kanan atas aplikasi kasir: jumlah belum dibaca + daftar terbaru.
 * Diperiksa tiap 30 detik; notifikasi penting / peringatan baru juga muncul sebagai toast.
 */
class LoncengNotifikasi extends Component
{
    public const JUMLAH_DAFTAR = 12;

    /** Batas waktu notifikasi yang sudah ditampilkan sebagai toast */
    public string $sejak = '';

    public function mount(): void
    {
        $this->sejak = now()->toDateTimeString();
    }

    private function cabangId(): ?string
    {
        return app(Tenancy::class)->cabangId();
    }

    #[Computed]
    public function daftar(): Collection
    {
        $u = auth()->user();

        if (! $u?->tenant_id) {
            return collect();
        }

        $daftar = Lonceng::untuk($u, $this->cabangId())->with('cabang:id,nama')->limit(self::JUMLAH_DAFTAR)->get();
        $dibaca = Lonceng::idDibaca($u, $daftar->pluck('id')->all());

        return $daftar->each(fn (Notifikasi $n) => $n->setAttribute('dibaca', in_array($n->id, $dibaca, true)));
    }

    #[Computed]
    public function jumlah(): int
    {
        $u = auth()->user();

        return $u?->tenant_id ? Lonceng::jumlahBelumDibaca($u, $this->cabangId()) : 0;
    }

    /** Polling: toast untuk notifikasi penting / peringatan yang baru masuk */
    public function periksa(): void
    {
        $u = auth()->user();

        if (! $u?->tenant_id) {
            return;
        }

        $baru = Lonceng::belumDibaca(Lonceng::untuk($u, $this->cabangId()), $u)
            ->where('created_at', '>', $this->sejak)->whereIn('tingkat', ['penting', 'peringatan'])
            ->limit(3)->get();

        foreach ($baru->reverse() as $n) {
            $this->dispatch('ui:toast', icon: $n->tingkat === 'penting' ? 'error' : 'warning', title: $n->judul);
        }

        $this->sejak = now()->toDateTimeString();
    }

    public function buka(string $id): void
    {
        $u = auth()->user();
        $n = Lonceng::untuk($u, $this->cabangId())->whereKey($id)->first();

        if (! $n) {
            return;
        }

        Lonceng::tandaiDibaca($u, $n->id);

        // Halaman kasir: navigasi Livewire; panel admin: muat penuh
        $url = $n->url ?: route('notifikasi', absolute: false);
        $this->redirect($url, navigate: ! str_starts_with($url, '/admin'));
    }

    public function tandaiSemua(): void
    {
        Lonceng::tandaiSemua(auth()->user());
        unset($this->daftar, $this->jumlah);
    }

    public function render()
    {
        return view('livewire.operator.lonceng-notifikasi');
    }
}
