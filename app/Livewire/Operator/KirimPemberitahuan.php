<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\WithAlert;
use App\Models\PerangkatTv;
use App\Models\Unit;
use App\Services\Tv\TvRemoteService;
use App\Support\Audit;
use App\Support\PemberitahuanTv;
use App\Support\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Panel "Pemberitahuan" di halaman Rental: kirim pesan ke tengah layar TV satu unit (atau semua TV cabang),
 * pilih pesan cepat / ketik sendiri + emoji, durasi tampil, ukuran, jenis huruf & tebal.
 */
class KirimPemberitahuan extends Component
{
    use WithAlert;

    public bool $buka = false;

    public ?string $unitId = null;

    /** Kirim ke semua TV di cabang ini */
    public bool $semua = false;

    public string $teks = '';

    public int $detik = 10;

    public string $ukuran = 'besar';

    public string $huruf = 'sans';

    public bool $tebal = true;

    #[On('buka-pemberitahuan')]
    public function bukaUntuk(?string $unitId = null): void
    {
        if (! auth()->user()->can('rental.kelola')) {
            $this->error('Anda tidak punya izin mengirim pemberitahuan');

            return;
        }

        $this->unitId = $unitId;
        $this->semua = $unitId === null;
        unset($this->unit);
        $this->buka = true;
    }

    #[Computed]
    public function unit(): ?Unit
    {
        return $this->unitId ? Unit::find($this->unitId) : null;
    }

    /** @return array<int, string> */
    #[Computed]
    public function pesanCepat(): array
    {
        return PemberitahuanTv::pesanCepat(app(Tenancy::class)->cabangId());
    }

    public function pilihPesan(int $i): void
    {
        $this->teks = $this->pesanCepat[$i] ?? $this->teks;
    }

    public function tambahEmoji(string $emoji): void
    {
        if (in_array($emoji, PemberitahuanTv::EMOJI, true) && mb_strlen($this->teks) < PemberitahuanTv::MAKS_TEKS) {
            $this->teks = rtrim($this->teks).($this->teks === '' ? '' : ' ').$emoji;
        }
    }

    public function kirim(TvRemoteService $remote): void
    {
        if (! auth()->user()->can('rental.kelola')) {
            return;
        }

        $tujuan = $this->semua
            ? PerangkatTv::aktif()->where('cabang_id', app(Tenancy::class)->cabangId())->whereNotNull('unit_id')->get()
            : PerangkatTv::aktif()->where('unit_id', $this->unitId)->get();

        if ($tujuan->isEmpty()) {
            $this->error($this->semua ? 'Belum ada TV di cabang ini' : 'Unit ini belum punya TV');

            return;
        }

        try {
            $n = $remote->pemberitahuan($tujuan, $this->isi(), auth()->user());
        } catch (BillingException $e) {
            $this->alert('Belum terkirim', $e->getMessage(), 'error');

            return;
        }

        Audit::catat('pemberitahuan_tv', 'Pemberitahuan ke '.($this->semua ? "{$n} TV" : 'TV '.$this->unit?->nama).': '.trim($this->teks));

        $this->buka = false;
        $this->reset('teks');
        $this->success("Pemberitahuan tampil di {$n} TV");
    }

    private function isi(): array
    {
        return ['teks' => $this->teks, 'detik' => $this->detik, 'ukuran' => $this->ukuran, 'huruf' => $this->huruf, 'tebal' => $this->tebal];
    }

    public function render()
    {
        return view('livewire.operator.kirim-pemberitahuan');
    }
}
