<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Exceptions\PlafonTerlampaui;
use App\Livewire\Concerns\WithAlert;
use App\Models\Aset;
use App\Models\Cabang;
use App\Models\Maintenance;
use App\Models\Unit;
use App\Services\Aset\AsetService;
use App\Services\Aset\MaintenanceService;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.operator')]
#[Title('Maintenance')]
class DaftarMaintenance extends Component
{
    use WithAlert;

    /** terbuka | selesai | semua */
    #[Url(except: 'terbuka')]
    public string $tab = 'terbuka';

    // Tiket baru
    public bool $formBuka = false;

    public array $form = [];

    // Selesai
    public bool $selesaiBuka = false;

    public ?string $selesaiId = null;

    public array $hasil = [];

    /* ---------------- Data ---------------- */

    #[Computed]
    public function daftar(): Collection
    {
        return Maintenance::query()
            ->with(['unit:id,nama', 'aset:id,nama,kategori', 'user:id,name', 'penyelesai:id,name'])
            ->when($this->tab === 'terbuka', fn ($q) => $q->terbuka()->orderByRaw("FIELD(status, 'dikerjakan', 'dijadwalkan')")->orderByRaw('dijadwalkan_pada IS NULL')->orderBy('dijadwalkan_pada'))
            ->when($this->tab === 'selesai', fn ($q) => $q->whereIn('status', ['selesai', 'batal'])->latest('selesai_pada')->latest('updated_at'))
            ->when($this->tab === 'semua', fn ($q) => $q->latest())
            ->limit(100)
            ->get();
    }

    #[Computed]
    public function jatuhTempo(): Collection
    {
        return app(AsetService::class)->jatuhTempoServis();
    }

    #[Computed]
    public function ringkasan(): array
    {
        return [
            'dikerjakan' => Maintenance::query()->where('status', 'dikerjakan')->count(),
            'dijadwalkan' => Maintenance::query()->where('status', 'dijadwalkan')->count(),
            'biaya_bulan' => (int) Maintenance::query()->where('status', 'selesai')
                ->where('selesai_pada', '>=', now()->startOfMonth())->sum('biaya'),
        ];
    }

    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->urut()->get(['id', 'nama', 'status']);
    }

    #[Computed]
    public function asetPilihan(): Collection
    {
        return Aset::query()->dimiliki()
            ->when(($this->form['unit_id'] ?? '') !== '', fn ($q) => $q->where('unit_id', $this->form['unit_id']))
            ->orderBy('nama')->get(['id', 'nama', 'unit_id']);
    }

    /* ---------------- Aksi ---------------- */

    #[On('buka-maintenance')]
    public function tambah(?string $unitId = null, ?string $asetId = null): void
    {
        $this->izin();
        $this->resetValidation();
        $aset = $asetId ? Aset::find($asetId) : null;
        $this->form = [
            'unit_id' => $unitId ?? (string) $aset?->unit_id,
            'aset_id' => (string) $aset?->id,
            'jenis' => $aset ? 'servis_rutin' : 'perbaikan',
            'judul' => $aset ? __('Servis berkala :aset', ['aset' => $aset->nama]) : '',
            'deskripsi' => '',
            'vendor' => '',
            'dijadwalkan_pada' => today()->toDateString(),
            'mulai' => false,
        ];
        unset($this->asetPilihan);
        $this->formBuka = true;
    }

    public function updatedFormUnitId(): void
    {
        unset($this->asetPilihan);

        if (($this->form['aset_id'] ?? '') !== '' && ! $this->asetPilihan->contains('id', $this->form['aset_id'])) {
            $this->form['aset_id'] = '';
        }
    }

    public function simpan(MaintenanceService $service): void
    {
        $this->izin();
        $this->validate([
            'form.jenis' => 'required|in:'.implode(',', array_keys(Maintenance::JENIS)),
            'form.judul' => 'required|string|min:3|max:150',
            'form.unit_id' => 'nullable|string',
            'form.aset_id' => 'nullable|string',
            'form.deskripsi' => 'nullable|string|max:1000',
            'form.vendor' => 'nullable|string|max:100',
            'form.dijadwalkan_pada' => 'nullable|date',
        ], ['form.judul.required' => 'Tulis masalah / pekerjaannya.']);

        try {
            $service->buat($this->cabang(), auth()->user(), $this->form);
        } catch (BillingException $e) {
            $this->addError('form.judul', $e->getMessage());

            return;
        }

        $this->formBuka = false;
        $this->segarkan();
        $this->success('Tiket maintenance dibuat');
    }

    public function mulai(string $id, array $konfirmasi = []): void
    {
        $this->izin();

        try {
            app(MaintenanceService::class)->mulai(Maintenance::findOrFail($id), auth()->user());
        } catch (BillingException $e) {
            $this->alert('Tidak bisa dimulai', $e->getMessage(), 'error');

            return;
        }

        $this->segarkan();
        $this->success('Maintenance dimulai');
        $this->dispatch('sesi-berubah');
    }

    public function bukaSelesai(string $id): void
    {
        $this->izin();
        $m = Maintenance::findOrFail($id);
        $this->resetValidation();
        $this->selesaiId = $m->id;
        $this->hasil = [
            'hasil' => '', 'biaya' => null, 'vendor' => (string) $m->vendor,
            'catat_pengeluaran' => false, 'sumber_dana' => 'rekening', 'aset_rusak' => false,
        ];
        $this->selesaiBuka = true;
    }

    public function simpanSelesai(MaintenanceService $service): void
    {
        $this->izin();
        $this->validate([
            'hasil.hasil' => 'nullable|string|max:1000',
            'hasil.biaya' => 'nullable|integer|min:0',
            'hasil.vendor' => 'nullable|string|max:100',
            'hasil.sumber_dana' => 'required|in:kas_laci,rekening',
        ]);

        if (! empty($this->hasil['catat_pengeluaran']) && ! auth()->user()->can('pengeluaran.catat')) {
            $this->addError('hasil.biaya', 'Anda tidak punya izin mencatat pengeluaran.');

            return;
        }

        try {
            $service->selesai(Maintenance::findOrFail($this->selesaiId), auth()->user(), $this->hasil);
        } catch (PlafonTerlampaui $e) {
            $this->addError('hasil.biaya', 'Melebihi batas pengeluaran kas laci shift. Pilih sumber Rekening, atau catat lewat menu Pengeluaran dengan PIN.');

            return;
        } catch (BillingException $e) {
            $this->addError('hasil.biaya', $e->getMessage());

            return;
        }

        $this->selesaiBuka = false;
        $this->segarkan();
        $this->success('Maintenance selesai');
        $this->dispatch('sesi-berubah');
    }

    public function batal(string $id, array $konfirmasi = []): void
    {
        $this->izin();
        $alasan = trim($konfirmasi['reason'] ?? '');

        if (mb_strlen($alasan) < 3) {
            $this->alert('Tidak bisa dibatalkan', 'Alasan wajib diisi.', 'error');

            return;
        }

        try {
            app(MaintenanceService::class)->batal(Maintenance::findOrFail($id), auth()->user(), $alasan);
        } catch (BillingException $e) {
            $this->alert('Tidak bisa dibatalkan', $e->getMessage(), 'error');

            return;
        }

        $this->segarkan();
        $this->info('Tiket dibatalkan');
        $this->dispatch('sesi-berubah');
    }

    private function segarkan(): void
    {
        unset($this->daftar, $this->jatuhTempo, $this->ringkasan, $this->units);
    }

    private function cabang(): Cabang
    {
        return Cabang::findOrFail(app(Tenancy::class)->cabangId());
    }

    private function izin(): void
    {
        abort_unless(auth()->user()->can('maintenance.kelola'), 403);
    }

    public function render()
    {
        return view('livewire.operator.daftar-maintenance');
    }
}
