<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\WithAlert;
use App\Models\Sesi;
use App\Models\Transaksi;
use App\Models\Unit;
use App\Services\Billing\BillingService;
use App\Services\PinService;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class KelolaSesi extends Component
{
    use WithAlert;

    public const PILIHAN_MENIT = [15, 30, 60, 120];

    public const ALASAN_PINDAH = ['Stik bermasalah', 'TV bermasalah', 'Konsol bermasalah', 'Permintaan pelanggan'];

    public bool $buka = false;

    public ?string $sesiId = null;

    /** utama | tambah | pindah */
    public string $panel = 'utama';

    // Tambah waktu
    public ?int $tambahMenit = 30;

    public bool $gratis = false;

    public string $alasanGratis = '';

    public string $pinGratis = '';

    // Bonus waktu (kompensasi PS restart / hang): menit diketik operator
    public const PILIHAN_BONUS = [5, 10, 15, 30];

    public const ALASAN_BONUS = ['PS restart sendiri', 'PS hang / freeze', 'Stik error', 'TV bermasalah', 'Listrik padam'];

    public ?int $bonusMenit = 10;

    public string $bonusAlasan = '';

    public string $bonusPin = '';

    // Pindah unit
    public ?string $unitTujuanId = null;

    public string $alasanPindah = '';

    public bool $unitLamaServis = false;

    #[On('buka-kelola-sesi')]
    public function bukaUntuk(string $unitId, string $panel = 'utama'): void
    {
        $sesi = Sesi::query()
            ->aktif()
            ->where('unit_id', $unitId)
            ->latest('mulai_pada')
            ->first();

        if (! $sesi) {
            $this->error('Tidak ada sesi aktif di unit ini');
            $this->dispatch('sesi-berubah');

            return;
        }

        $this->sesiId = $sesi->id;

        // Panel tambah waktu hanya untuk paket
        $this->kePanel($panel === 'tambah' && ! $sesi->isPaket() ? 'utama' : $panel);
        $this->buka = true;
    }

    public function kePanel(string $panel): void
    {
        $this->resetValidation();
        $this->reset(['tambahMenit', 'gratis', 'alasanGratis', 'pinGratis', 'unitTujuanId', 'alasanPindah', 'unitLamaServis', 'bonusMenit', 'bonusAlasan', 'bonusPin']);
        $this->panel = in_array($panel, ['utama', 'tambah', 'pindah', 'bonus'], true) ? $panel : 'utama';
        $this->segarkanData();
    }

    /* ---------------- Data ---------------- */

    #[Computed]
    public function sesi(): ?Sesi
    {
        return $this->sesiId
            ? Sesi::with(['unit.tipeKonsol:id,kode', 'paketHarga:id,nama', 'transaksi.items'])->find($this->sesiId)
            : null;
    }

    #[Computed]
    public function unitKosong(): Collection
    {
        if (! $this->sesi) {
            return collect();
        }

        return Unit::aktif()
            ->where('status', Unit::STATUS_KOSONG)
            ->whereKeyNot($this->sesi->unit_id)
            ->with('tipeKonsol:id,kode')
            ->urut()
            ->get();
    }

    #[Computed]
    public function tarifPerJam(): ?int
    {
        return $this->sesi
            ? rescue(fn () => $this->billing()->tarifPerJam($this->sesi->unit), null, false)
            : null;
    }

    #[Computed]
    public function hargaTambah(): int
    {
        if (! $this->sesi?->isPaket() || $this->gratis || ! $this->tambahMenit || ! $this->tarifPerJam) {
            return 0;
        }

        return intdiv($this->tarifPerJam * (int) $this->tambahMenit + 59, 60);
    }

    /* ---------------- Aksi ---------------- */

    public function tambahWaktu(): void
    {
        $this->validate([
            'tambahMenit' => 'required|integer|min:1|max:720',
            'alasanGratis' => 'nullable|required_if:gratis,true|string|max:200',
        ], [
            'tambahMenit.required' => 'Isi jumlah menit.',
            'tambahMenit.min' => 'Minimal 1 menit.',
            'tambahMenit.max' => 'Maksimal 720 menit (12 jam) sekali tambah.',
            'alasanGratis.required_if' => 'Alasan wajib diisi untuk tambah waktu gratis.',
        ]);

        $menit = (int) $this->tambahMenit;
        $alasan = trim($this->alasanGratis) ?: null;

        // Tambah waktu gratis wajib disetujui PIN pemilik izin sesi.gratis
        if ($this->gratis) {
            try {
                $penyetuju = app(PinService::class)->setujui($this->pinGratis, 'sesi.gratis', app(Tenancy::class)->tenantId());
            } catch (BillingException $e) {
                $this->addError('pinGratis', $e->getMessage());
                $this->pinGratis = '';

                return;
            }

            if ($penyetuju->id !== auth()->id()) {
                $alasan .= " (disetujui {$penyetuju->name})";
            }
        }

        $this->jalankan(
            fn () => $this->billing()->tambahWaktu($this->sesi, auth()->user(), $menit, $this->gratis, $alasan),
            "Waktu ditambah {$menit} menit"
        );
    }

    /** Open billing berjalan: perkiraan sewa sampai saat ini */
    #[Computed]
    public function estimasiSewa(): ?int
    {
        return $this->sesi ? $this->billing()->estimasiSewaOpen($this->sesi) : null;
    }

    /** Owner/supervisor (izin "tambah waktu gratis") langsung; selain itu perlu PIN orang yang berizin */
    #[Computed]
    public function bonusButuhPin(): bool
    {
        return ! auth()->user()->can('sesi.gratis');
    }

    public function bonusWaktu(): void
    {
        $this->validate([
            'bonusMenit' => 'required|integer|min:1|max:240',
            'bonusAlasan' => 'required|string|max:200',
        ], [
            'bonusMenit.required' => 'Isi berapa menit bonus.',
            'bonusMenit.min' => 'Minimal 1 menit.',
            'bonusMenit.max' => 'Maksimal 240 menit (4 jam).',
            'bonusAlasan.required' => 'Pilih atau tulis alasan bonus.',
        ]);

        $alasan = trim($this->bonusAlasan);

        if ($this->bonusButuhPin) {
            try {
                $penyetuju = app(PinService::class)->setujui($this->bonusPin, 'sesi.gratis', app(Tenancy::class)->tenantId());
            } catch (BillingException $e) {
                $this->addError('bonusPin', $e->getMessage());
                $this->bonusPin = '';

                return;
            }

            $alasan .= " (disetujui {$penyetuju->name})";
        }

        $menit = (int) $this->bonusMenit;
        $paket = $this->sesi?->isPaket();

        if ($this->jalankan(
            fn () => $this->billing()->bonusWaktu($this->sesi, auth()->user(), $menit, $alasan),
            $paket ? "Bonus {$menit} menit: waktu selesai diundur" : "Bonus {$menit} menit: tidak ditagih"
        )) {
            $this->bonusAlasan = '';
            $this->bonusPin = '';
        }
    }

    public function pause(): void
    {
        $this->jalankan(fn () => $this->billing()->pause($this->sesi, auth()->user()), 'Sesi dijeda');
    }

    public function resume(): void
    {
        $this->jalankan(fn () => $this->billing()->resume($this->sesi, auth()->user()), 'Sesi dilanjutkan');
    }

    public function pindah(): void
    {
        $this->validate([
            'unitTujuanId' => 'required|string',
            'alasanPindah' => 'required|string|min:3|max:200',
        ], [
            'unitTujuanId.required' => 'Pilih unit tujuan.',
            'alasanPindah.required' => 'Alasan pindah wajib diisi.',
            'alasanPindah.min' => 'Alasan terlalu pendek.',
        ]);

        $tujuan = Unit::find($this->unitTujuanId);

        if (! $tujuan) {
            $this->addError('unitTujuanId', 'Unit tujuan tidak ditemukan.');

            return;
        }

        $this->jalankan(
            fn () => $this->billing()->pindahUnit($this->sesi, $tujuan, auth()->user(), trim($this->alasanPindah), $this->unitLamaServis),
            "Sesi dipindah ke {$tujuan->nama}"
        );
    }

    /** Dipanggil dari tombol konfirmasi (parameter terakhir berisi hasil konfirmasi) */
    public function selesai(array $konfirmasi = []): void
    {
        $transaksiId = $this->sesi?->transaksi_id;

        $berhasil = $this->jalankan(
            fn () => $this->billing()->selesai($this->sesi, auth()->user()),
            'Sesi selesai',
            tutup: true
        );

        // Langsung buka pembayaran jika masih ada tagihan
        if ($berhasil && $transaksiId) {
            $trx = Transaksi::find($transaksiId);

            if ($trx && ! $trx->isLunas() && $trx->sisaTagihan() > 0) {
                $this->dispatch('buka-pembayaran', transaksiId: $transaksiId);
            }
        }
    }

    /* ---------------- Helper ---------------- */

    private function jalankan(callable $aksi, string $pesan, bool $tutup = false): bool
    {
        if (! $this->sesi) {
            $this->buka = false;

            return false;
        }

        try {
            $aksi();
        } catch (BillingException $e) {
            $this->alert('Tidak bisa diproses', $e->getMessage(), 'error');

            return false;
        }

        $this->success($pesan);
        $this->dispatch('sesi-berubah');

        if ($tutup) {
            $this->buka = false;

            return true;
        }

        $this->kePanel('utama');

        return true;
    }

    private function segarkanData(): void
    {
        unset($this->sesi, $this->unitKosong, $this->tarifPerJam, $this->hargaTambah, $this->estimasiSewa);
    }

    private function billing(): BillingService
    {
        return app(BillingService::class);
    }

    public function render()
    {
        return view('livewire.operator.kelola-sesi');
    }
}
