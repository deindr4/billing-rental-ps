<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\WithAlert;
use App\Models\Aset;
use App\Models\Cabang;
use App\Models\Maintenance;
use App\Models\ModalMutasi;
use App\Models\Unit;
use App\Services\Aset\AsetService;
use App\Services\Aset\ModalService;
use App\Support\Gambar;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

#[Layout('layouts.operator')]
#[Title('Aset & Modal')]
class AsetModal extends Component
{
    use WithAlert, WithFileUploads;

    #[Url(except: '')]
    public string $kategori = '';

    #[Url(except: false)]
    public bool $dilepas = false;

    // Form aset
    public bool $formBuka = false;

    public ?string $editId = null;

    public array $form = [];

    public $foto = null;

    // Lepas aset
    public bool $lepasBuka = false;

    public ?string $lepasId = null;

    public string $lepasTanggal = '';

    public ?int $lepasNilai = null;

    public string $lepasCatatan = '';

    // Modal / prive
    public bool $modalBuka = false;

    public string $modalJenis = 'modal';

    public ?int $modalJumlah = null;

    public string $modalSumber = 'rekening';

    public string $modalTanggal = '';

    public string $modalKeterangan = '';

    /* ---------------- Data ---------------- */

    #[Computed]
    public function ringkasan(): array
    {
        return app(AsetService::class)->ringkasan();
    }

    #[Computed]
    public function daftar(): Collection
    {
        return Aset::query()
            ->with('unit:id,nama,kode')
            ->when($this->kategori !== '', fn ($q) => $q->where('kategori', $this->kategori))
            ->when(! $this->dilepas, fn ($q) => $q->dimiliki())
            ->when($this->dilepas, fn ($q) => $q->where('status', 'dilepas'))
            ->orderByRaw("FIELD(status, 'servis', 'rusak', 'aktif', 'dilepas')")
            ->orderBy('kategori')
            ->orderBy('nama')
            ->get();
    }

    /** Omzet & jam operasional per unit sejak aset dibeli */
    #[Computed]
    public function statistik(): array
    {
        $sejak = [];

        foreach ($this->daftar->whereNotNull('unit_id') as $a) {
            $tgl = $a->tanggal_beli->toDateString();
            $sejak[$a->unit_id] = isset($sejak[$a->unit_id]) ? min($sejak[$a->unit_id], $tgl) : $tgl;
        }

        return app(AsetService::class)->statistikUnit($sejak);
    }

    #[Computed]
    public function modalBulanIni(): Collection
    {
        return ModalMutasi::query()
            ->with('user:id,name')
            ->whereBetween('tanggal', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->latest('tanggal')
            ->latest()
            ->get();
    }

    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->urut()->get(['id', 'nama', 'kode']);
    }

    #[Computed]
    public function servisTerbuka(): array
    {
        return Maintenance::query()->terbuka()->whereNotNull('aset_id')->pluck('aset_id')->flip()->all();
    }

    public function bisa(string $izin): bool
    {
        return (bool) auth()->user()?->can($izin);
    }

    /* ---------------- Aset ---------------- */

    public function tambah(): void
    {
        $this->izin('aset.kelola');
        $this->resetValidation();
        $this->editId = null;
        $this->foto = null;
        $this->form = [
            'kategori' => 'konsol', 'nama' => '', 'merek' => '', 'serial' => '', 'unit_id' => '',
            'tanggal_beli' => today()->toDateString(), 'harga_perolehan' => null, 'nilai_sisa' => 0,
            'umur_bulan' => Aset::UMUR_BAWAAN['konsol'], 'garansi_sampai' => '', 'interval_servis_hari' => '',
            'catatan' => '',
        ];
        $this->formBuka = true;
    }

    public function ubah(string $id): void
    {
        $this->izin('aset.kelola');
        $a = Aset::findOrFail($id);
        $this->resetValidation();
        $this->editId = $a->id;
        $this->foto = null;
        $this->form = [
            'kategori' => $a->kategori, 'nama' => $a->nama, 'merek' => (string) $a->merek, 'serial' => (string) $a->serial,
            'unit_id' => (string) $a->unit_id, 'tanggal_beli' => $a->tanggal_beli->toDateString(),
            'harga_perolehan' => $a->harga_perolehan, 'nilai_sisa' => $a->nilai_sisa, 'umur_bulan' => $a->umur_bulan,
            'garansi_sampai' => $a->garansi_sampai?->toDateString() ?? '', 'interval_servis_hari' => (string) ($a->interval_servis_hari ?? ''),
            'catatan' => (string) $a->catatan,
        ];
        $this->formBuka = true;
    }

    public function updatedFormKategori(string $kategori): void
    {
        if (! $this->editId && isset(Aset::UMUR_BAWAAN[$kategori])) {
            $this->form['umur_bulan'] = Aset::UMUR_BAWAAN[$kategori];
        }
    }

    public function simpan(AsetService $service): void
    {
        $this->izin('aset.kelola');

        $this->validate([
            'form.kategori' => 'required|in:'.implode(',', array_keys(Aset::KATEGORI)),
            'form.nama' => 'required|string|min:2|max:120',
            'form.merek' => 'nullable|string|max:60',
            'form.serial' => 'nullable|string|max:80',
            'form.unit_id' => 'nullable|string',
            'form.tanggal_beli' => 'required|date|before_or_equal:today',
            'form.harga_perolehan' => 'required|integer|min:1',
            'form.nilai_sisa' => 'nullable|integer|min:0',
            'form.umur_bulan' => 'required|integer|min:1|max:240',
            'form.garansi_sampai' => 'nullable|date',
            'form.interval_servis_hari' => 'nullable|integer|min:1|max:730',
            'form.catatan' => 'nullable|string|max:500',
            'foto' => 'nullable|image|max:8192',
        ], [
            'form.nama.required' => 'Nama aset wajib diisi.',
            'form.harga_perolehan.required' => 'Harga perolehan wajib diisi.',
        ]);

        $data = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $this->form);
        $data['cabang_id'] = app(Tenancy::class)->cabangId();
        $data['nilai_sisa'] = (int) ($data['nilai_sisa'] ?? 0);

        try {
            $aset = $service->simpan($data, $this->editId ? Aset::findOrFail($this->editId) : null);

            if ($this->foto) {
                $path = Gambar::simpanWebp($this->foto->getRealPath(), 'tenants/'.$aset->tenant_id.'/aset', ...Gambar::FOTO);

                if ($aset->foto) {
                    Storage::disk('public')->delete($aset->foto);
                }

                $aset->update(['foto' => $path]);
            }
        } catch (BillingException $e) {
            $this->addError('form.harga_perolehan', $e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->addError('foto', __('Foto gagal diproses: :pesan', ['pesan' => $e->getMessage()]));

            return;
        }

        $this->formBuka = false;
        $this->segarkan();
        $this->success($this->editId ? __('Aset disimpan') : __('Aset ditambahkan'));
    }

    public function hapus(string $id, array $konfirmasi = []): void
    {
        $this->izin('aset.kelola');

        try {
            app(AsetService::class)->hapus(Aset::findOrFail($id));
        } catch (BillingException $e) {
            $this->alert('Tidak bisa dihapus', $e->getMessage(), 'error');

            return;
        }

        $this->formBuka = false;
        $this->segarkan();
        $this->success('Aset dihapus');
    }

    public function bukaLepas(string $id): void
    {
        $this->izin('aset.kelola');
        $this->resetValidation();
        $this->lepasId = $id;
        $this->lepasTanggal = today()->toDateString();
        $this->lepasNilai = null;
        $this->lepasCatatan = '';
        $this->lepasBuka = true;
    }

    public function simpanLepas(AsetService $service): void
    {
        $this->izin('aset.kelola');
        $this->validate([
            'lepasTanggal' => 'required|date|before_or_equal:today',
            'lepasNilai' => 'nullable|integer|min:0',
            'lepasCatatan' => 'nullable|string|max:200',
        ]);

        try {
            $service->lepas(Aset::findOrFail($this->lepasId), $this->lepasTanggal, $this->lepasNilai, trim($this->lepasCatatan) ?: null);
        } catch (BillingException $e) {
            $this->addError('lepasTanggal', $e->getMessage());

            return;
        }

        $this->lepasBuka = false;
        $this->formBuka = false;
        $this->segarkan();
        $this->success('Aset dilepas');
    }

    /* ---------------- Modal & prive ---------------- */

    public function bukaModal(string $jenis): void
    {
        $this->izin('modal.kelola');
        $this->resetValidation();
        $this->modalJenis = $jenis === 'prive' ? 'prive' : 'modal';
        $this->modalJumlah = null;
        $this->modalSumber = 'rekening';
        $this->modalTanggal = today()->toDateString();
        $this->modalKeterangan = '';
        $this->modalBuka = true;
    }

    public function simpanModal(ModalService $service): void
    {
        $this->izin('modal.kelola');
        $this->validate([
            'modalJenis' => 'required|in:modal,prive',
            'modalJumlah' => 'required|integer|min:1',
            'modalSumber' => 'required|in:rekening,kas_laci',
            'modalTanggal' => 'required|date|before_or_equal:today',
            'modalKeterangan' => 'required|string|min:3|max:255',
        ], ['modalJumlah.required' => 'Isi nominal.', 'modalKeterangan.required' => 'Keterangan wajib diisi.']);

        try {
            $service->catat(
                Cabang::findOrFail(app(Tenancy::class)->cabangId()),
                auth()->user(),
                $this->modalJenis,
                (int) $this->modalJumlah,
                $this->modalSumber,
                $this->modalTanggal,
                $this->modalKeterangan,
            );
        } catch (BillingException $e) {
            $this->addError('modalJumlah', $e->getMessage());

            return;
        }

        $this->modalBuka = false;
        $this->segarkan();
        $this->success(__(':jenis dicatat', ['jenis' => __(ModalMutasi::JENIS[$this->modalJenis])]));
    }

    public function batalModal(string $id, array $konfirmasi = []): void
    {
        $this->izin('modal.kelola');

        try {
            app(ModalService::class)->batalkan(ModalMutasi::findOrFail($id), auth()->user());
        } catch (BillingException $e) {
            $this->alert('Tidak bisa dibatalkan', $e->getMessage(), 'error');

            return;
        }

        $this->segarkan();
        $this->success('Dibatalkan');
    }

    private function segarkan(): void
    {
        unset($this->ringkasan, $this->daftar, $this->statistik, $this->modalBulanIni);
    }

    private function izin(string $izin): void
    {
        abort_unless($this->bisa($izin), 403);
    }

    public function render()
    {
        return view('livewire.operator.aset-modal');
    }
}
