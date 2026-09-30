<?php

namespace App\Livewire\Publik;

use App\Exceptions\BillingException;
use App\Models\PaketHarga;
use App\Models\PembayaranOnline;
use App\Models\Unit;
use App\Services\Gateway\BayarMandiriService;
use App\Services\Gateway\PengaturanGateway;
use App\Support\Tema;
use App\Support\Tenancy;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Halaman HP dari QR di TV: pelanggan mengetik nominal -> QRIS nominal itu tampil di TV -> bayar -> TV terbuka.
 * /main/{token unit}
 */
#[Layout('booking.layout')]
class MainMandiri extends Component
{
    #[Locked]
    public string $unitId = '';

    public ?int $nominal = null;

    #[Locked]
    public ?string $tagihanId = null;

    public function mount(string $token): void
    {
        $unit = Unit::withoutGlobalScopes()->where('token_bayar', $token)->first();
        abort_unless($unit, 404);

        $this->unitId = $unit->id;
        $this->hydrate();

        // QR yang masih aktif untuk unit ini (misal halaman dibuka ulang)
        $this->tagihanId = PembayaranOnline::query()->where('unit_id', $unit->id)->aktif()->latest()->value('id');
    }

    public function hydrate(): void
    {
        $unit = Unit::withoutGlobalScopes()->findOrFail($this->unitId);
        app(Tenancy::class)->set($unit->tenant_id, $unit->cabang_id);
    }

    #[Computed]
    public function unit(): Unit
    {
        return Unit::with(['tipeKonsol:id,nama', 'cabang.tenant'])->findOrFail($this->unitId);
    }

    #[Computed]
    public function konteks(): array
    {
        return app(BayarMandiriService::class)->konteks($this->unit);
    }

    #[Computed]
    public function tarif(): ?int
    {
        return app(BayarMandiriService::class)->tarif($this->unit);
    }

    #[Computed]
    public function minimal(): int
    {
        return app(PengaturanGateway::class)->minimalMenit($this->unit->cabang_id);
    }

    /** Pilihan cepat: 1/2/3 jam dari tarif + paket yang berlaku */
    #[Computed]
    public function pilihan(): array
    {
        $t = (int) $this->tarif;
        $hasil = [];

        foreach ([60, 120, 180] as $m) {
            if ($m >= $this->minimal && $t > 0) {
                $hasil[] = ['label' => ($m / 60).' jam', 'nominal' => intdiv($t * $m + 59, 60)];
            }
        }

        if (($this->konteks['jenis'] ?? null) === 'mulai') {
            foreach (PaketHarga::query()->untukUnit($this->unit)->aktif()->where('jenis', PaketHarga::JENIS_PAKET)->orderBy('harga')->get() as $p) {
                $hasil[] = ['label' => $p->nama, 'nominal' => (int) $p->harga];
            }
        }

        return collect($hasil)->unique('nominal')->values()->all();
    }

    /** Perkiraan durasi untuk nominal yang sedang diketik */
    public function perkiraan(): ?string
    {
        if (! $this->nominal || ! $this->tarif) {
            return null;
        }

        $layanan = app(BayarMandiriService::class);
        $h = $layanan->hitung($this->unit, (int) $this->nominal, ($this->konteks['jenis'] ?? null) === 'mulai');

        return $h['menit'] > 0 ? ($h['paket'] ? $h['paket']->nama.' · ' : '').$layanan->labelMenit($h['menit']) : null;
    }

    #[Computed]
    public function tagihan(): ?PembayaranOnline
    {
        if (! $this->tagihanId) {
            return null;
        }

        $t = PembayaranOnline::find($this->tagihanId);

        return $t ? app(BayarMandiriService::class)->periksa($t) : null;
    }

    public function pilih(int $nominal): void
    {
        $this->nominal = $nominal;
    }

    public function tampilkan(BayarMandiriService $layanan): void
    {
        $this->validate(['nominal' => 'required|integer|min:1000|max:2000000'], [
            'nominal.required' => 'Isi nominal.',
            'nominal.min' => 'Nominal terlalu kecil.',
        ]);

        $kunci = 'main-mandiri:'.request()->ip();

        if (RateLimiter::tooManyAttempts($kunci, 6)) {
            $this->addError('nominal', 'Terlalu banyak percobaan. Tunggu sebentar.');

            return;
        }

        RateLimiter::hit($kunci, 300);

        try {
            $t = $layanan->buatTagihan($this->unit, (int) $this->nominal);
        } catch (BillingException $e) {
            $this->addError('nominal', $e->getMessage());

            return;
        }

        $this->tagihanId = $t->id;
        unset($this->tagihan);
    }

    public function ganti(): void
    {
        $this->tagihanId = null;
        unset($this->tagihan, $this->konteks);
    }

    public function logo(): ?string
    {
        return Tema::logoUrl();
    }

    public function render()
    {
        return view('booking.main-mandiri')->title('Main di '.$this->unit->nama);
    }
}
