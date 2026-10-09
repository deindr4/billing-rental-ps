<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\UnggahSementara;
use App\Livewire\Concerns\WithAlert;
use App\Models\SewaPlaybox;
use App\Services\Playbox\PlayboxService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Terima Playbox kembali: checklist dibandingkan dengan saat keluar (kurang / rusak → biaya ganti), denda telat otomatis
 * (bisa diubah), foto kondisi, deposit dipotong tagihan & sisanya dikembalikan.
 */
#[Layout('layouts.operator')]
#[Title('Terima Kembali Playbox')]
class KembaliPlaybox extends Component
{
    use UnggahSementara, WithAlert, WithFileUploads;

    public SewaPlaybox $sewa;

    public array $checklist = [];

    public ?int $denda = null;

    public bool $potongDeposit = true;

    public string $metodeSisa = 'tunai';

    public array $fotoKondisi = [];

    public string $catatan = '';

    /** Hasil setelah disimpan */
    public ?array $hasil = null;

    public function mount(string $id): void
    {
        $this->sewa = SewaPlaybox::query()->with(['playbox', 'penyewa'])->findOrFail($id);

        if ($this->sewa->status !== 'berjalan') {
            $this->redirectRoute('playbox', navigate: true);

            return;
        }

        $this->denda = app(PlayboxService::class)->hitungDenda($this->sewa)['denda'];
        $this->checklist = array_map(fn ($c) => [
            'nama' => $c['nama'], 'keluar' => (int) $c['jumlah'], 'jumlah' => (int) $c['jumlah'], 'kondisi' => 'baik',
            'harga_ganti' => (int) ($c['harga_ganti'] ?? 0), 'biaya' => 0,
        ], (array) $this->sewa->checklist_keluar);
    }

    /** Kondisi / jumlah berubah → biaya bawaan: harga ganti × kurang, atau harga ganti bila rusak (bisa diubah) */
    public function updatedChecklist($nilai, string $kunci): void
    {
        [$i, $kolom] = explode('.', $kunci) + [null, null];

        if (! in_array($kolom, ['jumlah', 'kondisi'], true) || ! isset($this->checklist[$i])) {
            return;
        }

        $c = &$this->checklist[$i];
        $kurang = max(0, $c['keluar'] - (int) $c['jumlah']);

        if ($kurang > 0 && $c['kondisi'] === 'baik') {
            $c['kondisi'] = 'hilang';
        }

        $c['biaya'] = match ($c['kondisi']) {
            'hilang' => $c['harga_ganti'] * max(1, $kurang),
            'rusak' => $c['harga_ganti'],
            default => 0,
        };
    }

    public function tagihan(): int
    {
        return max(0, (int) $this->denda) + (int) array_sum(array_map(fn ($c) => max(0, (int) $c['biaya']), $this->checklist));
    }

    /** Dari tombol konfirmasi (parameter terakhir = hasil konfirmasi) */
    public function simpan(array $konfirmasi = []): void
    {
        try {
            $hasil = app(PlayboxService::class)->kembalikan($this->sewa, auth()->user(), [
                'checklist' => $this->checklist,
                'denda' => $this->denda,
                'potong_deposit' => $this->potongDeposit,
                'bayar_sisa' => ['metode' => $this->metodeSisa],
                'foto_kondisi' => $this->keFileBanyak($this->fotoKondisi),
                'catatan' => $this->catatan,
            ]);
        } catch (BillingException $e) {
            $this->alert('Tidak bisa diproses', $e->getMessage(), 'error');

            return;
        } catch (\RuntimeException) {
            $this->alert('Foto tidak terbaca', 'Ambil ulang foto (format gambar).', 'error');

            return;
        } finally {
            $this->hapusSementara();
        }

        $this->hasil = [
            'tagihan' => (int) ($hasil['transaksi']?->total ?? 0),
            'deposit_kembali' => $hasil['deposit_kembali'],
            'sisa' => $hasil['sisa'],
            'transaksi_id' => $hasil['transaksi']?->id,
            'status_unit' => $this->sewa->playbox->fresh()->status,
        ];

        // Tanpa deposit: tagihan denda / ganti rugi dibayar lewat dialog Pembayaran
        if ($hasil['sisa'] > 0 && $hasil['transaksi']) {
            $this->dispatch('buka-pembayaran', transaksiId: $hasil['transaksi']->id);
        }
    }

    public function render()
    {
        $info = app(PlayboxService::class)->hitungDenda($this->sewa);

        return view('livewire.operator.kembali-playbox', ['menitTelat' => $info['menit_telat']]);
    }
}
