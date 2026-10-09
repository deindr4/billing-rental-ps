<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\WithAlert;
use App\Models\Playbox;
use App\Models\SewaPlaybox;
use App\Services\Playbox\PlayboxService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Sewa Playbox bawa pulang: sewa berjalan (jatuh tempo, telat, belum bayar), unit, perpanjang, batal */
#[Layout('layouts.operator')]
#[Title('Sewa Playbox')]
class PlayboxSewa extends Component
{
    use WithAlert;

    #[Url(as: 'tab', except: 'berjalan')]
    public string $tab = 'berjalan';

    public string $cari = '';

    /** kotak | daftar — diingat per sesi login (unit banyak lebih ringkas sebagai daftar) */
    #[Session]
    public string $tampilan = 'kotak';

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
        unset($this->sewa, $this->unit);
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
        $teks = "Halo {$s->penyewa?->nama}, sewa {$s->playbox?->kode} ({$s->nomor}) jatuh tempo "
            .$s->jatuh_tempo->translatedFormat('l, d F Y \p\u\k\u\l H.i').'. Mohon dikembalikan tepat waktu ya. Terima kasih 🙏';

        return 'https://wa.me/'.$s->penyewa?->telepon.'?text='.rawurlencode($teks);
    }

    public function render()
    {
        return view('livewire.operator.playbox-sewa');
    }
}
