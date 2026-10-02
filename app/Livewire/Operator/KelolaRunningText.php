<?php

namespace App\Livewire\Operator;

use App\Livewire\Concerns\WithAlert;
use App\Support\Audit;
use App\Support\RunningTextTv;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Panel "Running text" di halaman Rental: teks berjalan promo di semua TV cabang,
 * nyala selama durasi tertentu, bisa disembunyikan otomatis saat unit sedang dimainkan.
 */
class KelolaRunningText extends Component
{
    use WithAlert;

    public bool $buka = false;

    public string $teks = '';

    /** Menit tayang; 0 = sampai dimatikan */
    public int $durasi = 60;

    public bool $sembunyiSaatMain = true;

    public string $posisi = 'bawah';

    public int $opasitas = 60;

    public string $ukuran = 'sedang';

    public bool $tebal = false;

    public string $kecepatan = 'sedang';

    public string $warna = 'putih';

    #[On('buka-running-text')]
    public function bukaPanel(): void
    {
        if (! auth()->user()->can('rental.kelola')) {
            $this->error('Anda tidak punya izin mengatur running text');

            return;
        }

        $rt = RunningTextTv::ambil($this->cabangId());
        $this->teks = (string) $rt['teks'];
        $this->sembunyiSaatMain = (bool) $rt['sembunyi_saat_main'];
        $this->posisi = $rt['posisi'];
        $this->opasitas = (int) $rt['opasitas'];
        $this->ukuran = $rt['ukuran'];
        $this->tebal = (bool) $rt['tebal'];
        $this->kecepatan = $rt['kecepatan'];
        $this->warna = $rt['warna'];
        $this->buka = true;
    }

    public function nyalakan(): void
    {
        if (! auth()->user()->can('rental.kelola')) {
            return;
        }

        $this->validate([
            'teks' => 'required|string|max:300',
            'durasi' => 'required|in:'.implode(',', array_keys(RunningTextTv::DURASI)),
            'posisi' => 'required|in:'.implode(',', array_keys(RunningTextTv::POSISI)),
            'opasitas' => 'required|integer|min:0|max:100',
            'ukuran' => 'required|in:'.implode(',', array_keys(RunningTextTv::UKURAN)),
            'kecepatan' => 'required|in:'.implode(',', array_keys(RunningTextTv::KECEPATAN)),
            'warna' => 'required|in:'.implode(',', array_keys(RunningTextTv::WARNA)),
        ], [], ['teks' => 'teks berjalan']);

        $sampai = $this->durasi > 0 ? now()->addMinutes($this->durasi) : null;
        RunningTextTv::simpan($this->isi(true, $sampai), $this->cabangId());

        Audit::catat('running_text_tv', 'Running text TV dinyalakan'.($sampai ? ' sampai '.$sampai->format('H:i') : '').': '.trim($this->teks));
        $this->buka = false;
        $this->success('Running text tayang di TV'.($sampai ? ' sampai '.$sampai->format('H:i') : ''));
        $this->dispatch('running-text-berubah');
    }

    public function matikan(): void
    {
        if (! auth()->user()->can('rental.kelola')) {
            return;
        }

        RunningTextTv::simpan($this->isi(false, null), $this->cabangId());

        Audit::catat('running_text_tv', 'Running text TV dimatikan');
        $this->buka = false;
        $this->success('Running text dimatikan');
        $this->dispatch('running-text-berubah');
    }

    private function isi(bool $aktif, ?Carbon $sampai): array
    {
        return [
            'aktif' => $aktif,
            'teks' => trim($this->teks),
            'sampai' => $sampai?->toIso8601String(),
            'sembunyi_saat_main' => $this->sembunyiSaatMain,
            'posisi' => $this->posisi,
            'opasitas' => max(0, min(100, $this->opasitas)),
            'ukuran' => $this->ukuran,
            'tebal' => $this->tebal,
            'kecepatan' => $this->kecepatan,
            'warna' => $this->warna,
        ];
    }

    private function cabangId(): ?string
    {
        return app(Tenancy::class)->cabangId();
    }

    public function render()
    {
        $rt = RunningTextTv::ambil($this->cabangId());

        return view('livewire.operator.kelola-running-text', [
            'tayang' => RunningTextTv::tayang($rt),
            'sampai' => $rt['sampai'] ? Carbon::parse($rt['sampai']) : null,
        ]);
    }
}
