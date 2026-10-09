<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Exceptions\PlafonTerlampaui;
use App\Livewire\Concerns\WithAlert;
use App\Models\Cabang;
use App\Models\Pengeluaran as PengeluaranModel;
use App\Services\Billing\PengeluaranService;
use App\Services\Billing\ShiftService;
use App\Services\PinService;
use App\Support\Gambar;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

#[Layout('layouts.operator')]
#[Title('Pengeluaran')]
class Pengeluaran extends Component
{
    use WithAlert, WithFileUploads;

    // Form
    public ?int $jumlah = null;

    public string $kategori = 'fnb';

    public string $keterangan = '';

    public string $sumberDana = 'kas_laci';

    /** @var TemporaryUploadedFile|null */
    public $foto = null;

    // Persetujuan plafon
    public bool $butuhPin = false;

    public string $pin = '';

    // Filter riwayat
    #[Url(except: '')]
    public string $tanggal = '';

    #[Url(as: 'kategori', except: '')]
    public string $filterKategori = '';

    public function mount(): void
    {
        $this->tanggal = rescue(fn () => Carbon::createFromFormat('Y-m-d', $this->tanggal)->toDateString(), null, false)
            ?? today()->toDateString();

    }

    /* ---------------- Data ---------------- */

    #[Computed]
    public function shift()
    {
        return app(ShiftService::class)->aktif(auth()->user(), app(Tenancy::class)->cabangId());
    }

    #[Computed]
    public function plafon(): array
    {
        $batas = $this->service()->plafonShift(app(Tenancy::class)->cabangId());
        $terpakai = $this->shift ? $this->service()->terpakaiShift($this->shift) : 0;

        return [
            'batas' => $batas,
            'terpakai' => $terpakai,
            'sisa' => max(0, $batas - $terpakai),
            'persen' => $batas > 0 ? min(100, (int) round($terpakai / $batas * 100)) : 0,
        ];
    }

    #[Computed]
    public function daftar(): Collection
    {
        return PengeluaranModel::query()
            ->with(['user:id,name', 'penyetuju:id,name'])
            ->whereDate('created_at', $this->tanggal)
            ->when($this->filterKategori !== '', fn ($q) => $q->where('kategori', $this->filterKategori))
            ->latest('created_at')
            ->get();
    }

    #[Computed]
    public function totalHari(): int
    {
        return (int) PengeluaranModel::query()
            ->whereDate('created_at', $this->tanggal)
            ->where('status', 'aktif')
            ->sum('jumlah');
    }

    /* ---------------- Form ---------------- */

    public function tambahNominal(int $nilai): void
    {
        $this->jumlah = max(0, (int) $this->jumlah + $nilai);
    }

    public function updated(string $properti): void
    {
        // Ubah isian -> minta ulang persetujuan
        if (in_array($properti, ['jumlah', 'sumberDana'], true)) {
            $this->butuhPin = false;
            $this->pin = '';
        }
    }

    public function simpan(): void
    {
        $this->validate([
            'jumlah' => 'required|integer|min:1|max:100000000',
            'kategori' => 'required|in:'.implode(',', array_keys(PengeluaranModel::KATEGORI_MANUAL)),
            'keterangan' => 'required|string|min:3|max:255',
            'sumberDana' => 'required|in:kas_laci,rekening',
            'foto' => 'nullable|image|max:5120',
        ], [
            'jumlah.required' => 'Isi nominal pengeluaran.',
            'keterangan.required' => 'Tulis keterangan / uraian nota.',
            'keterangan.min' => 'Keterangan terlalu pendek.',
            'foto.image' => 'Foto nota harus berupa gambar.',
            'foto.max' => 'Ukuran foto maksimal 5 MB.',
        ]);

        $tenancy = app(Tenancy::class);
        $penyetuju = null;

        if ($this->butuhPin) {
            try {
                $penyetuju = app(PinService::class)->setujui($this->pin, 'pengeluaran.lebih_plafon', $tenancy->tenantId());
            } catch (BillingException $e) {
                $this->addError('pin', $e->getMessage());
                $this->pin = '';

                return;
            }
        }

        $fotoPath = null;

        try {
            if ($this->foto) {
                $fotoPath = Gambar::simpanWebp($this->foto->getRealPath(), 'tenants/'.$tenancy->tenantId().'/nota');
            }

            $hasil = $this->service()->catat(
                Cabang::findOrFail($tenancy->cabangId()),
                auth()->user(),
                [
                    'kategori' => $this->kategori,
                    'jumlah' => (int) $this->jumlah,
                    'keterangan' => $this->keterangan,
                    'sumber_dana' => $this->sumberDana,
                ],
                $fotoPath,
                $penyetuju
            );
        } catch (PlafonTerlampaui $e) {
            $this->butuhPin = true;
            $this->addError('pin', $e->getMessage());

            return;
        } catch (BillingException $e) {
            $this->alert('Tidak bisa disimpan', $e->getMessage(), 'error');

            return;
        } catch (Throwable $e) {
            $this->addError('foto', __('Foto gagal diproses: :pesan', ['pesan' => $e->getMessage()]));

            return;
        }

        $this->reset(['jumlah', 'keterangan', 'foto', 'butuhPin', 'pin']);
        $this->tanggal = today()->toDateString();
        unset($this->daftar, $this->plafon, $this->totalHari);

        $this->success(__('Pengeluaran :nomor tersimpan', ['nomor' => $hasil->nomor]));
        $this->dispatch('transaksi-berubah');
    }

    /* ---------------- Batal ---------------- */

    /** Dipanggil dari tombol konfirmasi: reason + pin */
    public function batalkan(string $id, array $konfirmasi = []): void
    {
        $pengeluaran = PengeluaranModel::find($id);

        if (! $pengeluaran) {
            $this->error('Pengeluaran tidak ditemukan');

            return;
        }

        $alasan = trim($konfirmasi['reason'] ?? '');

        // Cek alasan sebelum ditambah nama penyetuju, supaya alasan kosong tidak lolos
        if (mb_strlen($alasan) < 5) {
            $this->alert('Tidak bisa dibatalkan', 'Alasan pembatalan wajib diisi (minimal 5 karakter).', 'error');

            return;
        }

        try {
            $penyetuju = app(PinService::class)->setujui($konfirmasi['pin'] ?? null, 'pengeluaran.batal', app(Tenancy::class)->tenantId());

            if ($penyetuju->id !== auth()->id()) {
                $alasan .= " (disetujui {$penyetuju->name})";
            }

            $this->service()->batalkan($pengeluaran, auth()->user(), $alasan);
        } catch (BillingException $e) {
            $this->alert('Tidak bisa dibatalkan', $e->getMessage(), 'error');

            return;
        }

        unset($this->daftar, $this->plafon, $this->totalHari);
        $this->success('Pengeluaran dibatalkan');
        $this->dispatch('transaksi-berubah');
    }

    private function service(): PengeluaranService
    {
        return app(PengeluaranService::class);
    }

    public function render()
    {
        return view('livewire.operator.pengeluaran');
    }
}
