<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\WithAlert;
use App\Models\PembayaranOnline;
use App\Models\Unit;
use App\Services\Gateway\BayarMandiriService;
use App\Services\Gateway\SimulasiGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Daftar pembayaran mandiri lewat QRIS di TV + penanganan yang perlu tindakan kasir */
#[Layout('layouts.operator')]
#[Title('Pembayaran online')]
class PembayaranOnlineKasir extends Component
{
    use WithAlert;

    #[Url(except: '')]
    public string $tanggal = '';

    public array $unitTujuan = [];

    public function mount(): void
    {
        $this->tanggal = rescue(fn () => Carbon::parse($this->tanggal)->toDateString(), null, false) ?? today()->toDateString();
    }

    #[Computed]
    public function daftar(): Collection
    {
        return PembayaranOnline::query()->with(['unit:id,nama'])
            ->where(fn ($q) => $q->whereDate('created_at', $this->tanggal)->orWhere('status', 'perlu_tindakan'))
            ->orderByRaw("FIELD(status, 'perlu_tindakan', 'dibayar', 'menunggu') DESC")
            ->latest()->get();
    }

    #[Computed]
    public function ringkasan(): array
    {
        $hari = PembayaranOnline::query()->whereDate('created_at', $this->tanggal)->whereIn('status', ['selesai', 'perlu_tindakan', 'dibayar']);

        return [
            'jumlah' => (clone $hari)->count(),
            'nominal' => (int) (clone $hari)->sum('nominal'),
            'biaya' => (int) (clone $hari)->sum('biaya'),
        ];
    }

    #[Computed]
    public function unitKosong(): Collection
    {
        return Unit::query()->aktif()->where('status', Unit::STATUS_KOSONG)->urut()->get(['id', 'nama']);
    }

    public function simulasiBayar(string $id, BayarMandiriService $layanan): void
    {
        $p = PembayaranOnline::findOrFail($id);

        try {
            SimulasiGateway::bayar((string) $p->referensi);
            $layanan->perbarui($p, 'dibayar', 0);
        } catch (BillingException $e) {
            $this->error($e->getMessage());

            return;
        }

        $this->segarkan();
        $this->success('Simulasi: pembayaran diterima');
        $this->dispatch('sesi-berubah');
    }

    public function terapkan(string $id, BayarMandiriService $layanan): void
    {
        $unit = Unit::find($this->unitTujuan[$id] ?? '');

        if (! $unit) {
            $this->error('Pilih unit tujuan');

            return;
        }

        try {
            $p = $layanan->terapkanKeUnit(PembayaranOnline::findOrFail($id), $unit);
        } catch (BillingException $e) {
            $this->alert('Tidak bisa diterapkan', $e->getMessage(), 'error');

            return;
        }

        $this->segarkan();
        $p->status === 'selesai'
            ? $this->success("Sesi dimulai di {$unit->nama}")
            : $this->alert('Belum berhasil', (string) $p->catatan, 'warning');
        $this->dispatch('sesi-berubah');
    }

    public function tandaiDiurus(string $id, array $konfirmasi = []): void
    {
        $alasan = trim($konfirmasi['reason'] ?? '');

        if (mb_strlen($alasan) < 3) {
            $this->alert('Alasan wajib diisi', 'Tulis penanganannya, misal: dikembalikan tunai / pindah jadwal.', 'error');

            return;
        }

        $p = PembayaranOnline::findOrFail($id);
        $p->update(['status' => 'selesai', 'catatan' => mb_substr(($p->catatan ? $p->catatan.' · ' : '').'Diurus kasir: '.$alasan, 0, 255), 'diproses_pada' => now()]);
        $this->segarkan();
        $this->success('Ditandai selesai');
    }

    public function segarkan(): void
    {
        unset($this->daftar, $this->ringkasan, $this->unitKosong);
    }

    public function render()
    {
        return view('livewire.operator.pembayaran-online');
    }
}
