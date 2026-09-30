<?php

namespace App\Livewire\Publik;

use App\Exceptions\BillingException;
use App\Models\Booking;
use App\Models\Cabang;
use App\Models\Member;
use App\Models\PaketHarga;
use App\Models\TipeKonsol;
use App\Models\Unit;
use App\Services\Publik\BookingService;
use App\Support\Tema;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Portal booking publik: /booking/{kode cabang}. Pilih tanggal, konsol, durasi, jam, lalu isi nama & WhatsApp.
 */
#[Layout('booking.layout')]
class BookingPortal extends Component
{
    #[Locked]
    public string $cabangId = '';

    public string $tanggal = '';

    public string $tipe = '';

    public int $durasi = 0;

    public string $jam = '';

    public string $nama = '';

    public string $telepon = '';

    public string $catatan = '';

    /** Jebakan bot: harus tetap kosong */
    public string $situs = '';

    #[Locked]
    public ?string $bookingId = null;

    // Cek booking
    public bool $modeCek = false;

    public string $cekKode = '';

    public string $cekTelepon = '';

    public function mount(string $kode): void
    {
        $cabang = Cabang::withoutGlobalScopes()->where('kode', $kode)->where('is_active', true)->first();
        abort_unless($cabang, 404);

        $this->cabangId = $cabang->id;
        $this->hydrate();

        $this->tanggal = $this->pilihanTanggal[0]['nilai'] ?? today()->toDateString();
        $this->durasi = $this->aturan['durasi'][0] ?? 60;
    }

    public function hydrate(): void
    {
        $cabang = Cabang::withoutGlobalScopes()->findOrFail($this->cabangId);
        app(Tenancy::class)->set($cabang->tenant_id, $cabang->id);
    }

    /* ---------------- Data ---------------- */

    #[Computed]
    public function cabang(): Cabang
    {
        return Cabang::withoutGlobalScopes()->with('tenant')->findOrFail($this->cabangId);
    }

    #[Computed]
    public function aturan(): array
    {
        return app(BookingService::class)->aturan($this->cabangId);
    }

    #[Computed]
    public function pilihanTanggal(): array
    {
        return collect(range(0, $this->aturan['maks_hari']))->map(function ($i) {
            $t = today()->addDays($i);

            return ['nilai' => $t->toDateString(), 'label' => match ($i) {
                0 => 'Hari ini', 1 => 'Besok', default => $t->translatedFormat('D'),
            }, 'tgl' => $t->format('d/m')];
        })->all();
    }

    #[Computed]
    public function pilihanTipe()
    {
        $ids = Unit::query()->aktif()->pluck('tipe_konsol_id')->unique()->filter();

        return TipeKonsol::query()->whereIn('id', $ids)->orderBy('urutan')->get(['id', 'kode', 'nama']);
    }

    /** Tarif per jam termurah untuk tipe terpilih (perkiraan harga di tombol durasi) */
    #[Computed]
    public function tarif(): ?int
    {
        $unit = Unit::query()->aktif()->when($this->tipe !== '', fn ($q) => $q->where('tipe_konsol_id', $this->tipe))->first();

        return $unit ? rescue(fn () => (int) PaketHarga::query()->untukUnit($unit)->aktif()
            ->where('jenis', PaketHarga::JENIS_PER_JAM)->min('harga'), null, false) : null;
    }

    #[Computed]
    public function slot(): array
    {
        if (! $this->aturan['aktif']) {
            return [];
        }

        return collect(app(BookingService::class)->slot($this->cabang, Carbon::parse($this->tanggal), $this->durasi, $this->tipe ?: null))
            ->map(fn ($s) => ['jam' => $s['jam'], 'sisa' => $s['unit']->count()])
            ->all();
    }

    #[Computed]
    public function booking(): ?Booking
    {
        return $this->bookingId ? Booking::with('unit:id,nama')->find($this->bookingId) : null;
    }

    public function logo(): ?string
    {
        return Tema::logoUrl();
    }

    /* ---------------- Aksi ---------------- */

    public function updated($prop): void
    {
        if (in_array($prop, ['tanggal', 'tipe', 'durasi'], true)) {
            $this->jam = '';
            unset($this->slot, $this->tarif);
        }
    }

    public function pilihJam(string $jam): void
    {
        $this->jam = $jam;
    }

    public function pesan(BookingService $service): void
    {
        $this->validate([
            'tanggal' => 'required|date',
            'durasi' => 'required|integer|min:30',
            'jam' => 'required|date_format:H:i',
            'nama' => 'required|string|min:2|max:100',
            'telepon' => 'required|string|min:9|max:20',
            'catatan' => 'nullable|string|max:200',
        ], [
            'jam.required' => 'Pilih jam main.',
            'nama.required' => 'Nama wajib diisi.',
            'telepon.required' => 'Nomor WhatsApp wajib diisi.',
            'telepon.min' => 'Nomor WhatsApp terlalu pendek.',
        ]);

        if ($this->situs !== '') {
            abort(422);
        }

        $kunci = 'booking-publik:'.request()->ip();

        if (RateLimiter::tooManyAttempts($kunci, 5)) {
            $this->addError('jam', 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.');

            return;
        }

        RateLimiter::hit($kunci, 600);

        // Jam lewat tengah malam (jam tutup > 24:00) masuk ke tanggal berikutnya
        $mulai = Carbon::parse($this->tanggal.' '.$this->jam);

        if ($mulai->lt(Carbon::parse($this->tanggal.' '.$this->aturan['jam_buka']))) {
            $mulai->addDay();
        }

        try {
            $b = $service->buat($this->cabang, [
                'nama' => $this->nama,
                'telepon' => $this->telepon,
                'mulai' => $mulai->toDateTimeString(),
                'durasi' => $this->durasi,
                'tipe_konsol_id' => $this->tipe ?: null,
                'catatan' => $this->catatan,
            ]);
        } catch (BillingException $e) {
            $this->addError('jam', $e->getMessage());
            unset($this->slot);

            return;
        }

        $this->bookingId = $b->id;
    }

    public function bookingBaru(): void
    {
        $this->reset(['bookingId', 'jam', 'catatan', 'modeCek', 'cekKode', 'cekTelepon']);
        unset($this->slot);
    }

    public function cek(): void
    {
        $this->validate(['cekKode' => 'required|string|max:12', 'cekTelepon' => 'required|string|max:20'], [
            'cekKode.required' => 'Isi kode booking.', 'cekTelepon.required' => 'Isi nomor WhatsApp.',
        ]);

        $b = Booking::query()->where('kode', strtoupper(trim($this->cekKode)))
            ->where('telepon', Member::normalisasiTelepon($this->cekTelepon))->first();

        if (! $b) {
            $this->addError('cekKode', 'Booking tidak ditemukan. Periksa kode & nomor WhatsApp.');

            return;
        }

        $this->bookingId = $b->id;
        $this->modeCek = false;
    }

    public function batalkan(BookingService $service): void
    {
        $b = $this->booking;

        if (! $b || ! $b->isAktif() || $b->mulai_pada->lt(now()->addMinutes(30))) {
            $this->addError('batal', 'Booking tidak bisa dibatalkan lagi. Hubungi kasir.');

            return;
        }

        $service->batal($b, 'Dibatalkan pelanggan lewat portal');
        unset($this->booking);
    }

    public function render()
    {
        return view('booking.portal')->title('Booking · '.$this->cabang->nama);
    }
}
