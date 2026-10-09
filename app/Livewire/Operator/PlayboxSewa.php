<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\WithAlert;
use App\Models\Playbox;
use App\Models\SewaPlaybox;
use App\Services\Playbox\PlayboxService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Sewa Playbox bawa pulang: sewa berjalan (jatuh tempo, telat, belum bayar), unit, perpanjang, batal */
#[Layout('layouts.operator')]
#[Title('Sewa Playbox')]
class PlayboxSewa extends Component
{
    use WithAlert, WithPagination;

    #[Url(as: 'tab', except: 'berjalan')]
    public string $tab = 'berjalan';

    public string $cari = '';

    /** kotak | daftar — diingat per sesi login (unit banyak lebih ringkas sebagai daftar) */
    #[Session]
    public string $tampilan = 'kotak';

    public const PER_HALAMAN = 15;

    // Perpanjang
    public ?string $perpanjangId = null;

    public string $satuan = 'hari';

    public int $jumlah = 1;

    public function mount(): void
    {
        // Baru dibuat → langsung buka dialog Pembayaran (bayar di muka)
        if ($id = request()->query('bayar')) {
            $this->dispatch('buka-pembayaran', transaksiId: $id);
        }
    }

    #[On('sesi-berubah')]
    #[On('pembayaran-berhasil')]
    public function segarkan(): void
    {
        unset($this->sewa, $this->halamanSewa, $this->unit);
    }

    #[Computed]
    public function sewa(): Collection
    {
        $q = SewaPlaybox::query()->with(['playbox:id,kode,nama', 'penyewa', 'transaksi:id,total,status'])
            ->when($this->tab === 'berjalan', fn ($q) => $q->berjalan()->orderBy('jatuh_tempo'))
            ->when($this->tab === 'riwayat', fn ($q) => $q->where('status', '!=', 'berjalan')->latest('updated_at')->limit(50));

        if (trim($this->cari) !== '') {
            $kata = '%'.trim($this->cari).'%';
            $q->where(fn ($w) => $w->where('nomor', 'like', $kata)
                ->orWhereHas('penyewa', fn ($p) => $p->where('nama', 'like', $kata)->orWhere('telepon', 'like', $kata)));
        }

        return $q->get();
    }

    /** Tampilan daftar: per halaman agar tidak memanjang */
    #[Computed]
    public function halamanSewa(): LengthAwarePaginator
    {
        $semua = $this->sewa;
        $halaman = min($this->getPage(), max(1, (int) ceil($semua->count() / self::PER_HALAMAN)));

        return new LengthAwarePaginator($semua->forPage($halaman, self::PER_HALAMAN)->values(), $semua->count(), self::PER_HALAMAN, $halaman);
    }

    public function updated(string $properti): void
    {
        if (in_array($properti, ['tab', 'cari', 'tampilan'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function unit(): Collection
    {
        return Playbox::aktif()->orderBy('urutan')->orderBy('kode')->get();
    }

    public function bukaPerpanjang(string $id): void
    {
        $s = $this->sewa->firstWhere('id', $id);
        $this->perpanjangId = $id;
        $this->satuan = $s?->satuan ?? 'hari';
        $this->jumlah = 1;
    }

    public function perpanjang(PlayboxService $layanan): void
    {
        $s = SewaPlaybox::query()->find($this->perpanjangId);

        try {
            $trx = $layanan->perpanjang($s, auth()->user(), $this->satuan, max(1, $this->jumlah));
        } catch (BillingException $e) {
            $this->alert('Tidak bisa diperpanjang', $e->getMessage(), 'error');

            return;
        }

        $this->perpanjangId = null;
        $this->segarkan();
        $this->success("Diperpanjang sampai {$s->fresh()->jatuh_tempo->format('d/m H:i')} · bayar perpanjangan");
        $this->dispatch('buka-pembayaran', transaksiId: $trx->id);
    }

    public function bayar(string $transaksiId): void
    {
        $this->dispatch('buka-pembayaran', transaksiId: $transaksiId);
    }

    /** Dari tombol konfirmasi beralasan */
    public function batal(string $id, array $konfirmasi = []): void
    {
        $s = SewaPlaybox::query()->find($id);

        try {
            app(PlayboxService::class)->batal($s, auth()->user(), (string) ($konfirmasi['reason'] ?? ''));
        } catch (BillingException $e) {
            $this->alert('Tidak bisa dibatalkan', $e->getMessage(), 'error');

            return;
        }

        $this->segarkan();
        $this->success('Sewa dibatalkan, deposit dikembalikan');
    }

    /** Link wa.me berisi ringkasan sewa (dibuka di HP / WA Web kasir) */
    public static function linkWa(SewaPlaybox $s): string
    {
        // Pesan ke penyewa memakai bahasa cabang (bukan bahasa kasir)
        $teks = \App\Support\Bahasa::dengan(\App\Support\Bahasa::cabang($s->cabang_id), fn () => __(
            'Halo :nama, sewa :kode (:nomor) jatuh tempo :waktu. Mohon dikembalikan tepat waktu ya. Terima kasih 🙏',
            ['nama' => $s->penyewa?->nama, 'kode' => $s->playbox?->kode, 'nomor' => $s->nomor, 'waktu' => $s->jatuh_tempo->translatedFormat('l, d F Y H.i')]
        ));

        return 'https://wa.me/'.$s->penyewa?->telepon.'?text='.rawurlencode($teks);
    }

    public function render()
    {
        return view('livewire.operator.playbox-sewa');
    }
}
