<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\WithAlert;
use App\Models\LogTv;
use App\Models\PerangkatTv;
use App\Models\Unit;
use App\Services\PinService;
use App\Services\Tv\BypassTvService;
use App\Services\Tv\KodeDarurat;
use App\Services\Tv\StatusTvService;
use App\Services\Tv\TvRemoteService;
use App\Support\Audit;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Panel TV di halaman Rental: status TV, bypass & kode darurat (keduanya disetujui PIN).
 */
class KelolaTv extends Component
{
    use WithAlert;

    public bool $buka = false;

    public ?string $unitId = null;

    /** Kode darurat yang sedang ditampilkan (hilang saat panel ditutup / dibuka ulang) */
    public ?string $kodeDarurat = null;

    public ?int $kodeBerlakuSampai = null;

    /** Durasi bypass / perpanjangan yang dipilih (menit) */
    public ?int $menitBypass = null;

    #[On('buka-kelola-tv')]
    public function bukaUntuk(string $unitId): void
    {
        if (! auth()->user()->can('rental.kelola')) {
            $this->error('Anda tidak punya izin mengelola TV');

            return;
        }

        $this->unitId = $unitId;
        $this->reset(['kodeDarurat', 'kodeBerlakuSampai', 'menitBypass']);
        unset($this->unit, $this->perangkat, $this->riwayat, $this->pilihanMenit);

        if (! $this->unit) {
            $this->error('Unit tidak ditemukan');

            return;
        }

        if ($this->perangkat) {
            $this->menitBypass = app(StatusTvService::class)->durasiBypass($this->perangkat);
        }

        $this->buka = true;
    }

    /** @return array<int,int> pilihan durasi bypass (menit) */
    #[Computed]
    public function pilihanMenit(): array
    {
        return $this->perangkat ? app(StatusTvService::class)->pilihanBypass($this->perangkat) : [];
    }

    public function pilihMenit(int $menit): void
    {
        if (in_array($menit, $this->pilihanMenit, true)) {
            $this->menitBypass = $menit;
        }
    }

    #[Computed]
    public function unit(): ?Unit
    {
        return $this->unitId ? Unit::find($this->unitId) : null;
    }

    #[Computed]
    public function perangkat(): ?PerangkatTv
    {
        return $this->unitId ? PerangkatTv::aktif()->where('unit_id', $this->unitId)->first() : null;
    }

    #[Computed]
    public function riwayat(): Collection
    {
        return $this->perangkat
            ? LogTv::with('user:id,name')->where('perangkat_id', $this->perangkat->id)->latest()->limit(5)->get()
            : collect();
    }

    /** Dipanggil dari tombol konfirmasi: $konfirmasi['pin'] */
    public function bypass(array $konfirmasi = []): void
    {
        if (! $perangkat = $this->perangkat) {
            return;
        }

        try {
            $penyetuju = $this->setujui($konfirmasi, 'tv.bypass');
        } catch (BillingException $e) {
            $this->alert('Bypass ditolak', $e->getMessage(), 'error');

            return;
        }

        app(BypassTvService::class)->mulai($perangkat, $penyetuju, 'operator', auth()->user(), $this->menitBypass);

        $this->segarkan();
        $this->success('TV dibuka sampai '.$perangkat->bypass_sampai->format('H:i'));
    }

    /** Dipanggil dari tombol konfirmasi: $konfirmasi['pin'] */
    public function perpanjangBypass(array $konfirmasi = []): void
    {
        if (! $perangkat = $this->perangkat) {
            return;
        }

        try {
            $penyetuju = $this->setujui($konfirmasi, 'tv.bypass');
        } catch (BillingException $e) {
            $this->alert('Ditolak', $e->getMessage(), 'error');

            return;
        }

        app(BypassTvService::class)->perpanjang($perangkat, $penyetuju, $this->menitBypass ?? 30, 'operator', auth()->user());

        $this->segarkan();
        $this->success('Bypass diperpanjang sampai '.$perangkat->bypass_sampai->format('H:i'));
    }

    public function akhiriBypass(): void
    {
        if (! $perangkat = $this->perangkat) {
            return;
        }

        app(BypassTvService::class)->akhiri($perangkat, 'operator', auth()->user());

        $this->segarkan();
        $this->success('Bypass diakhiri, TV terkunci kembali');
    }

    /**
     * Lock: akhiri unlock (bypass) lalu perintahkan TV menampilkan & mengunci lagi aplikasinya
     * (juga setelah aplikasi ditutup). Tanpa PIN — mengunci selalu aman.
     */
    public function kunci(): void
    {
        if (! $perangkat = $this->perangkat) {
            return;
        }

        app(BypassTvService::class)->akhiri($perangkat, 'operator', auth()->user());

        try {
            app(TvRemoteService::class)->kirim($perangkat->refresh(), 'kunci', auth()->user());
        } catch (BillingException $e) {
            $this->alert('Gagal', $e->getMessage(), 'error');

            return;
        }

        $this->segarkan();
        $this->success('TV dikunci kembali');
    }

    /** Tutup aplikasi TV Agent (TV bebas sampai Lock / sesi berikutnya). Dipanggil dari tombol konfirmasi: $konfirmasi['pin'] */
    public function tutupAplikasi(array $konfirmasi = []): void
    {
        if (! $perangkat = $this->perangkat) {
            return;
        }

        try {
            $this->setujui($konfirmasi, 'tv.bypass');
            app(TvRemoteService::class)->kirim($perangkat, 'tutup_aplikasi', auth()->user());
        } catch (BillingException $e) {
            $this->alert('Ditolak', $e->getMessage(), 'error');

            return;
        }

        $this->segarkan();
        $this->success('Aplikasi TV ditutup. Tekan Lock untuk mengunci lagi.');
    }

    /** Dipanggil dari tombol konfirmasi: $konfirmasi['pin'] */
    public function lihatKodeDarurat(array $konfirmasi = []): void
    {
        $perangkat = $this->perangkat;

        if (! $perangkat?->rahasia_offline) {
            return;
        }

        try {
            $penyetuju = $this->setujui($konfirmasi, 'tv.bypass');
        } catch (BillingException $e) {
            $this->alert('Ditolak', $e->getMessage(), 'error');

            return;
        }

        LogTv::catat($perangkat, 'kode_darurat', ['lewat' => 'operator', 'pemohon' => auth()->user()->name], $penyetuju);
        Audit::catat('kode_darurat', "Kode darurat TV {$perangkat->unit?->nama} dilihat (disetujui {$penyetuju->name})", $perangkat);

        $this->kodeDarurat = KodeDarurat::buat($perangkat->rahasia_offline);
        $this->kodeBerlakuSampai = now()->addSeconds(KodeDarurat::sisaDetik())->getTimestampMs();
        unset($this->riwayat);
    }

    public function tutupKode(): void
    {
        $this->reset(['kodeDarurat', 'kodeBerlakuSampai']);
    }

    private function setujui(array $konfirmasi, string $izin)
    {
        return app(PinService::class)->setujui($konfirmasi['pin'] ?? null, $izin, app(Tenancy::class)->tenantId());
    }

    private function segarkan(): void
    {
        unset($this->perangkat, $this->riwayat);
        $this->dispatch('sesi-berubah'); // grid rental ikut memperbarui label TV
    }

    public function render()
    {
        return view('livewire.operator.kelola-tv');
    }
}
