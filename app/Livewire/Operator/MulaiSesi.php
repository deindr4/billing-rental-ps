<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\PilihMember;
use App\Livewire\Concerns\WithAlert;
use App\Models\PaketHarga;
use App\Models\Pengaturan;
use App\Models\Unit;
use App\Services\Billing\BillingService;
use App\Services\Publik\BookingService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class MulaiSesi extends Component
{
    use PilihMember, WithAlert;

    public const MODE = [
        'paket' => 'Paket',
        'durasi' => 'Durasi',
        'open' => 'Open Billing',
    ];

    public const PILIHAN_DURASI = [30, 60, 90, 120, 180];

    public bool $buka = false;

    public ?string $unitId = null;

    /** paket | durasi | open */
    public string $mode = 'durasi';

    public ?string $paketId = null;

    public ?int $durasiMenit = 60;

    public string $pelanggan = '';

    /** Beri waktu pilih game (menit dari pengaturan) sebelum waktu sewa berjalan */
    public bool $pilihGame = false;

    /** Booking yang sedang check-in (ditautkan ke sesi setelah dimulai) */
    public ?string $bookingId = null;

    #[On('buka-mulai-sesi')]
    /**
     * @param  string|null  $pelanggan  nama dari antrean lounge / booking
     * @param  string|null  $memberId  member dari antrean / booking
     * @param  int|null  $durasi  durasi booking (menit)
     */
    public function bukaUntuk(string $unitId, ?string $pelanggan = null, ?string $memberId = null, ?int $durasi = null, ?string $bookingId = null): void
    {
        $this->resetValidation();
        $this->reset(['mode', 'paketId', 'durasiMenit', 'pelanggan']);
        $this->resetPilihMember();
        $this->pilihGame = $this->pilihGameDefault > 0 && (bool) Pengaturan::ambil('sesi.pilih_game_otomatis', true);
        $this->unitId = $unitId;

        unset($this->unit, $this->paketTersedia, $this->tarifPerJam);

        if (! $this->unit || ! $this->unit->isKosong()) {
            $this->error('Unit sedang tidak tersedia');

            return;
        }

        $this->mode = 'durasi';
        $this->paketId = $this->paketTersedia->first()?->id;
        $this->bookingId = $bookingId;

        // Isian dari antrean lounge / booking
        if ($durasi) {
            $this->durasiMenit = $durasi;
        }

        if ($memberId) {
            $this->pilihMember($memberId);
        } elseif ($pelanggan) {
            $this->pelanggan = $pelanggan;
        }

        $this->buka = true;
    }

    /* ---------------- Data ---------------- */

    #[Computed]
    public function unit(): ?Unit
    {
        return $this->unitId
            ? Unit::with(['tipeKonsol:id,kode,nama', 'kategori:id,nama'])->find($this->unitId)
            : null;
    }

    #[Computed]
    public function paketTersedia(): Collection
    {
        if (! $this->unit) {
            return collect();
        }

        return PaketHarga::query()
            ->untukUnit($this->unit)
            ->aktif()
            ->where('jenis', PaketHarga::JENIS_PAKET)
            ->orderBy('urutan')
            ->orderBy('durasi_menit')
            ->get();
    }

    #[Computed]
    public function tarifPerJam(): ?int
    {
        return $this->unit
            ? rescue(fn () => app(BillingService::class)->tarifPerJam($this->unit), null, false)
            : null;
    }

    /** Menit waktu pilih game dari pengaturan cabang (0 = fitur mati) */
    #[Computed]
    public function pilihGameDefault(): int
    {
        return max(0, min(15, (int) Pengaturan::ambil('sesi.pilih_game_menit', 5)));
    }

    #[Computed]
    public function aturanOpen(): array
    {
        return [
            'blok' => (int) Pengaturan::ambil('open_billing.blok_menit', 15),
            'minimal' => (int) Pengaturan::ambil('open_billing.minimal_menit', 60),
            'toleransi' => (int) Pengaturan::ambil('open_billing.toleransi_menit', 5),
        ];
    }

    /** Harga durasi dari tarif per jam (sama dengan perhitungan di server) */
    public function hargaDurasi(?int $menit = null): int
    {
        $menit ??= (int) $this->durasiMenit;

        return $this->tarifPerJam && $menit > 0 ? intdiv($this->tarifPerJam * $menit + 59, 60) : 0;
    }

    /** Perkiraan diskon tier member untuk tagihan awal (dihitung ulang di server) */
    public function diskonMember(): int
    {
        $tagihan = (int) $this->tagihanAwal();

        if ($this->jenisPelanggan !== 'member' || ! $this->member || $tagihan <= 0 || ! $this->programMemberAktif) {
            return 0;
        }

        return intdiv($tagihan * $this->infoMember()['diskon'], 100);
    }

    public function tagihanAwal(): ?int
    {
        return match ($this->mode) {
            'paket' => $this->paketTersedia->firstWhere('id', $this->paketId)?->harga,
            'durasi' => $this->hargaDurasi() ?: null,
            default => null,
        };
    }

    /* ---------------- Simpan ---------------- */

    public function simpan(BillingService $billing): void
    {
        $this->validate([
            'mode' => 'required|in:paket,durasi,open',
            'paketId' => 'nullable|required_if:mode,paket|string',
            'durasiMenit' => 'nullable|required_if:mode,durasi|integer|min:15|max:720',
            'pelanggan' => 'nullable|string|max:100',
            'memberId' => 'nullable|required_if:jenisPelanggan,member|string',
        ], [
            'memberId.required_if' => 'Pilih member, atau ganti ke Tamu.',
            'paketId.required_if' => 'Pilih paket terlebih dahulu.',
            'durasiMenit.required_if' => 'Pilih atau isi durasi.',
            'durasiMenit.min' => 'Durasi minimal 15 menit.',
            'durasiMenit.max' => 'Durasi maksimal 12 jam (720 menit).',
        ]);

        try {
            $sesi = $billing->mulai($this->unit, auth()->user(), [
                'mode' => $this->mode,
                'paket_harga_id' => $this->mode === 'paket' ? $this->paketId : null,
                'durasi_menit' => $this->mode === 'durasi' ? (int) $this->durasiMenit : null,
                'member_id' => $this->jenisPelanggan === 'member' ? $this->memberId : null,
                'pelanggan_nama' => $this->jenisPelanggan === 'tamu' ? (trim($this->pelanggan) ?: null) : null,
                'pilih_game_menit' => $this->pilihGame ? $this->pilihGameDefault : 0,
            ]);
        } catch (BillingException $e) {
            $this->alert('Tidak bisa memulai sesi', $e->getMessage(), 'error');

            return;
        }

        // Check-in booking: booking ditautkan ke sesi yang baru dimulai
        if ($this->bookingId) {
            app(BookingService::class)->checkin($this->bookingId, $sesi);
            $this->bookingId = null;
        }

        $this->buka = false;
        $this->success('Sesi dimulai di '.$this->unit->nama);
        $this->dispatch('sesi-berubah');
    }

    public function render()
    {
        return view('livewire.operator.mulai-sesi');
    }
}
