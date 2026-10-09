<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\WithAlert;
use App\Models\Booking;
use App\Models\Cabang;
use App\Models\Sesi;
use App\Models\Unit;
use App\Services\Publik\BookingService;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.operator')]
#[Title('Jadwal & Booking')]
class JadwalBooking extends Component
{
    use WithAlert;

    #[Url(except: '')]
    public string $tanggal = '';

    // Booking baru (dicatat kasir, misal lewat telepon)
    public bool $formBuka = false;

    public array $form = [];

    public function mount(): void
    {
        $this->tanggal = rescue(fn () => Carbon::parse($this->tanggal)->toDateString(), null, false) ?? today()->toDateString();
        app(BookingService::class)->tandaiKedaluwarsa();
    }

    /* ---------------- Data ---------------- */

    #[Computed]
    public function hari(): Carbon
    {
        return Carbon::parse($this->tanggal);
    }

    #[Computed]
    public function aturan(): array
    {
        return app(BookingService::class)->aturan(app(Tenancy::class)->cabangId());
    }

    #[Computed]
    public function daftar(): Collection
    {
        return Booking::query()->with(['unit:id,nama', 'member:id,kode,tier'])
            // Rentang (bukan whereDate) supaya index (cabang_id, mulai_pada) terpakai
            ->whereBetween('mulai_pada', [\Illuminate\Support\Carbon::parse($this->tanggal)->startOfDay(), \Illuminate\Support\Carbon::parse($this->tanggal)->endOfDay()])
            ->orderBy('mulai_pada')
            ->get();
    }

    #[Computed]
    public function menunggu(): int
    {
        return Booking::query()->where('status', 'menunggu')->where('selesai_pada', '>', now())->count();
    }

    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->aktif()->urut()->get(['id', 'nama', 'status']);
    }

    /** Linimasa per unit: blok booking & sesi berjalan pada rentang jam buka-tutup */
    #[Computed]
    public function linimasa(): array
    {
        $a = $this->aturan;
        $buka = Carbon::parse($this->tanggal.' '.$a['jam_buka']);
        $tutup = Carbon::parse($this->tanggal.' '.$a['jam_tutup']);

        if ($tutup->lte($buka)) {
            $tutup->addDay();
        }

        $total = max(1, $buka->diffInMinutes($tutup));
        $posisi = fn (Carbon $m, Carbon $s) => [
            'kiri' => max(0, min(100, $buka->diffInMinutes($m, false) / $total * 100)),
            'lebar' => max(1.5, min(100, $m->diffInMinutes($s) / $total * 100)),
        ];

        $sesi = $this->hari->isToday()
            ? Sesi::query()->aktif()->get()->keyBy('unit_id')
            : collect();

        return [
            'jam' => collect(range(0, (int) floor($total / 60)))->map(fn ($i) => $buka->copy()->addHours($i)->format('H'))->all(),
            'sekarang' => $this->hari->isToday() && now()->between($buka, $tutup) ? $buka->diffInMinutes(now()) / $total * 100 : null,
            'baris' => $this->units->map(fn (Unit $u) => [
                'unit' => $u,
                'blok' => $this->daftar->where('unit_id', $u->id)->whereIn('status', ['menunggu', 'dikonfirmasi', 'checkin'])
                    ->map(fn (Booking $b) => $posisi($b->mulai_pada, $b->selesai_pada) + ['b' => $b])->values()->all(),
                'sesi' => ($s = $sesi->get($u->id))
                    ? $posisi($s->mulai_pada, $s->berakhir_pada ?? now()->addMinutes(30))
                    : null,
            ])->all(),
        ];
    }

    #[Computed]
    public function slotKasir(): array
    {
        $durasi = (int) ($this->form['durasi'] ?? 60);

        if ($durasi < 30) {
            return [];
        }

        return collect(app(BookingService::class)->slot($this->cabang(), Carbon::parse($this->form['tanggal'] ?? $this->tanggal), $durasi, null, true))
            ->map(fn ($s) => ['jam' => $s['jam'], 'unit' => $s['unit']->pluck('nama', 'id')->all()])
            ->filter(fn ($s) => $s['unit'] !== [])
            ->values()->all();
    }

    /* ---------------- Aksi ---------------- */

    public function geser(int $hari): void
    {
        $this->tanggal = $this->hari->addDays($hari)->toDateString();
        $this->segarkan();
    }

    public function updatedTanggal(): void
    {
        $this->segarkan();
    }

    #[On('sesi-berubah')]
    public function segarkan(): void
    {
        unset($this->daftar, $this->linimasa, $this->menunggu, $this->units, $this->hari);
    }

    public function konfirmasi(string $id): void
    {
        $this->jalankan(fn (BookingService $s) => $s->konfirmasi(Booking::findOrFail($id), auth()->user()), __('Booking dikonfirmasi'));
    }

    public function batal(string $id, array $konfirmasi = []): void
    {
        $alasan = trim($konfirmasi['reason'] ?? '');

        if (mb_strlen($alasan) < 3) {
            $this->alert('Tidak bisa dibatalkan', 'Alasan wajib diisi.', 'error');

            return;
        }

        $this->jalankan(fn (BookingService $s) => $s->batal(Booking::findOrFail($id), $alasan, auth()->user()), __('Booking dibatalkan'));
    }

    public function tidakDatang(string $id, array $konfirmasi = []): void
    {
        $this->jalankan(fn (BookingService $s) => $s->tidakDatang(Booking::findOrFail($id)), __('Ditandai tidak datang'));
    }

    /** Pindahkan booking ke unit lain (misal unit semula masih dipakai / rusak) */
    public function pindahUnit(string $id, string $unitId): void
    {
        $b = Booking::findOrFail($id);

        if (! $b->isAktif()) {
            return;
        }

        $bentrok = Booking::query()->bentrok($unitId, $b->mulai_pada, $b->selesai_pada)->whereKeyNot($b->id)->exists();

        if ($bentrok) {
            $this->error('Unit itu sudah dibooking pada jam yang sama');

            return;
        }

        $b->update(['unit_id' => $unitId]);
        $this->segarkan();
        $this->success('Unit booking dipindah');
    }

    /** Pelanggan datang: buka Mulai Rental dengan data booking */
    public function checkin(string $id): void
    {
        $b = Booking::with('unit')->findOrFail($id);

        if (! $b->isAktif()) {
            $this->error('Booking ini sudah tidak aktif');

            return;
        }

        if (! $b->unit?->isKosong()) {
            $this->alert('Unit belum kosong', "{$b->unit?->nama} masih dipakai. Pindahkan booking ke unit lain yang kosong.", 'warning');

            return;
        }

        $this->dispatch('buka-mulai-sesi', unitId: $b->unit_id, pelanggan: $b->nama, memberId: $b->member_id, durasi: $b->durasi_menit, bookingId: $b->id);
    }

    public function bukaForm(): void
    {
        $this->resetValidation();
        $this->form = ['nama' => '', 'telepon' => '', 'tanggal' => $this->tanggal, 'durasi' => 60, 'jam' => '', 'unit_id' => '', 'catatan' => ''];
        unset($this->slotKasir);
        $this->formBuka = true;
    }

    public function updatedForm(): void
    {
        unset($this->slotKasir);
    }

    public function simpan(BookingService $service): void
    {
        $this->validate([
            'form.nama' => 'required|string|min:2|max:100',
            'form.telepon' => 'required|string|min:9|max:20',
            'form.tanggal' => 'required|date',
            'form.durasi' => 'required|integer|min:30|max:720',
            'form.jam' => 'required|date_format:H:i',
            'form.unit_id' => 'nullable|string',
        ], ['form.jam.required' => 'Pilih jam.', 'form.nama.required' => 'Nama wajib diisi.', 'form.telepon.required' => 'Nomor HP wajib diisi.']);

        $mulai = Carbon::parse($this->form['tanggal'].' '.$this->form['jam']);

        if ($mulai->lt(Carbon::parse($this->form['tanggal'].' '.$this->aturan['jam_buka']))) {
            $mulai->addDay();
        }

        try {
            $b = $service->buat($this->cabang(), [
                'nama' => $this->form['nama'],
                'telepon' => $this->form['telepon'],
                'mulai' => $mulai->toDateTimeString(),
                'durasi' => (int) $this->form['durasi'],
                'unit_id' => $this->form['unit_id'] ?: null,
                'catatan' => $this->form['catatan'],
            ], auth()->user());
        } catch (BillingException $e) {
            $this->addError('form.jam', $e->getMessage());

            return;
        }

        $this->formBuka = false;
        $this->tanggal = $b->mulai_pada->toDateString();
        $this->segarkan();
        $this->success(__('Booking :kode dibuat', ['kode' => $b->kode]));
    }

    private function jalankan(callable $aksi, string $pesan): void
    {
        try {
            $aksi(app(BookingService::class));
        } catch (BillingException $e) {
            $this->alert('Gagal', $e->getMessage(), 'error');

            return;
        }

        $this->segarkan();
        $this->success($pesan);
    }

    private function cabang(): Cabang
    {
        return Cabang::findOrFail(app(Tenancy::class)->cabangId());
    }

    public function render()
    {
        return view('livewire.operator.jadwal-booking');
    }
}
