<?php

namespace App\Livewire\Publik;

use App\Exceptions\BillingException;
use App\Models\Turnamen;
use App\Services\Publik\TurnamenService;
use App\Support\Tema;
use App\Support\Tenancy;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Halaman publik turnamen: /turnamen/{slug} — info, daftar online, peserta, bagan */
#[Layout('booking.layout')]
class TurnamenPublik extends Component
{
    #[Locked]
    public string $turnamenId = '';

    public string $nama = '';

    public string $telepon = '';

    public string $situs = '';

    public ?string $terdaftar = null;

    public function mount(string $slug): void
    {
        $t = Turnamen::withoutGlobalScopes()->where('slug', $slug)->whereNotIn('status', ['draft'])->first();
        abort_unless($t, 404);

        $this->turnamenId = $t->id;
        $this->hydrate();
    }

    public function hydrate(): void
    {
        $t = Turnamen::withoutGlobalScopes()->findOrFail($this->turnamenId);
        app(Tenancy::class)->set($t->tenant_id, $t->cabang_id);
    }

    #[Computed]
    public function turnamen(): Turnamen
    {
        return Turnamen::with('cabang.tenant')->findOrFail($this->turnamenId);
    }

    #[Computed]
    public function peserta()
    {
        return $this->turnamen->pesertaAktif()->orderBy('created_at')->get(['id', 'nama', 'status']);
    }

    /** Bagan / klasemen per bagian (semua format) */
    #[Computed]
    public function bagian(): array
    {
        return app(TurnamenService::class)->tampilan($this->turnamen);
    }

    public function juara(): array
    {
        return app(TurnamenService::class)->juara($this->turnamen);
    }

    public function logo(): ?string
    {
        return Tema::logoUrl();
    }

    public function daftar(TurnamenService $service): void
    {
        $this->validate(['nama' => 'required|string|min:2|max:100', 'telepon' => 'required|string|min:9|max:20'], [
            'nama.required' => 'Nama / gamer tag wajib diisi.', 'telepon.required' => 'Nomor WhatsApp wajib diisi.',
        ]);

        abort_if($this->situs !== '', 422);

        $kunci = 'turnamen-publik:'.request()->ip();

        if (RateLimiter::tooManyAttempts($kunci, 5)) {
            $this->addError('nama', 'Terlalu banyak percobaan. Coba lagi nanti.');

            return;
        }

        RateLimiter::hit($kunci, 600);

        try {
            $p = $service->daftar($this->turnamen, $this->nama, $this->telepon, 'online');
        } catch (BillingException $e) {
            $this->addError('nama', $e->getMessage());

            return;
        }

        $this->terdaftar = $p->nama;
        $this->reset(['nama', 'telepon']);
        unset($this->peserta, $this->turnamen);
    }

    public function render()
    {
        return view('booking.turnamen')->title($this->turnamen->nama);
    }
}
