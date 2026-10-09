<?php

namespace App\Livewire\Operator;

use App\Models\Notifikasi;
use App\Services\Notifikasi\Lonceng;
use App\Support\Tenancy;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Riwayat lonceng notifikasi (90 hari) dengan filter kelompok, tingkat & belum dibaca */
#[Layout('layouts.operator')]
#[Title('Notifikasi')]
class DaftarNotifikasi extends Component
{
    use WithPagination;

    #[Url]
    public string $kelompok = '';

    #[Url]
    public string $tingkat = '';

    #[Url]
    public bool $belum = false;

    public function updated(): void
    {
        $this->resetPage();
    }

    public function buka(string $id): void
    {
        $u = auth()->user();
        $n = Lonceng::untuk($u, app(Tenancy::class)->cabangId())->whereKey($id)->first();

        if (! $n) {
            return;
        }

        Lonceng::tandaiDibaca($u, $n->id);

        if ($n->url) {
            $this->redirect($n->url, navigate: ! str_starts_with($n->url, '/admin'));
        }
    }

    public function tandaiSemua(): void
    {
        Lonceng::tandaiSemua(auth()->user());
    }

    public function render()
    {
        $u = auth()->user();
        $q = Lonceng::untuk($u, app(Tenancy::class)->cabangId())->with('cabang:id,nama')
            ->when($this->kelompok !== '', fn ($q) => $q->where('kelompok', $this->kelompok))
            ->when($this->tingkat !== '', fn ($q) => $q->where('tingkat', $this->tingkat));

        if ($this->belum) {
            $q = Lonceng::belumDibaca($q, $u);
        }

        $halaman = $q->paginate(20);
        $dibaca = Lonceng::idDibaca($u, $halaman->pluck('id')->all());
        $halaman->getCollection()->each(fn (Notifikasi $n) => $n->setAttribute('dibaca', in_array($n->id, $dibaca, true)));

        // Hanya kelompok yang boleh dilihat pengguna ini
        $boleh = collect(Lonceng::jenisUntuk($u))->map(fn ($j) => Lonceng::JENIS[$j][0])->unique();

        return view('livewire.operator.daftar-notifikasi', [
            'halaman' => $halaman,
            'kelompokBoleh' => collect(Lonceng::KELOMPOK)->only($boleh->all()),
            'belumDibaca' => Lonceng::jumlahBelumDibaca($u, app(Tenancy::class)->cabangId()),
        ]);
    }
}
