<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Jobs\KirimNotifikasi;
use App\Livewire\Concerns\WithAlert;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Notifikasi\WhatsappService;
use App\Services\PinService;
use App\Services\Struk\StrukService;
use App\Support\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class DetailTransaksi extends Component
{
    use WithAlert;

    public const LABEL_LOG = [
        'mulai' => 'Sesi dimulai',
        'tambah_waktu' => 'Tambah waktu',
        'pause' => 'Dijeda',
        'resume' => 'Dilanjutkan',
        'pindah_unit' => 'Pindah unit',
        'tambah_fnb' => 'Tambah F&B',
        'selesai' => 'Sesi selesai',
        'bayar' => 'Pembayaran',
        'batal' => 'Dibatalkan',
    ];

    public const METODE = [
        'tunai' => 'Tunai',
        'qris' => 'QRIS',
        'transfer' => 'Transfer',
        'saldo' => 'Saldo Member',
    ];

    public bool $buka = false;

    public ?string $transaksiId = null;

    /** Form kirim struk digital ke WhatsApp pelanggan */
    public bool $formWa = false;

    public string $nomorWa = '';

    #[On('buka-detail-transaksi')]
    public function bukaUntuk(string $transaksiId): void
    {
        $this->transaksiId = $transaksiId;
        $this->reset(['formWa', 'nomorWa']);
        $this->resetValidation();
        unset($this->transaksi);

        if (! $this->transaksi) {
            $this->error('Transaksi tidak ditemukan');

            return;
        }

        $this->buka = true;
    }

    #[On('transaksi-berubah')]
    #[On('sesi-berubah')]
    public function segarkan(): void
    {
        unset($this->transaksi);
    }

    #[Computed]
    public function transaksi(): ?Transaksi
    {
        if (! $this->transaksiId) {
            return null;
        }

        return Transaksi::with([
            'items',
            'diskon',
            'pembayaran' => fn ($q) => $q->orderBy('dibayar_pada'),
            'unit:id,nama',
            'user:id,name',
            'sesi.log.user:id,name',
        ])->find($this->transaksiId);
    }

    #[Computed]
    public function namaPembatal(): ?string
    {
        $id = $this->transaksi?->dibatalkan_oleh;

        return $id ? User::whereKey($id)->value('name') : null;
    }

    /** Semua kasir bisa mengajukan pembatalan; persetujuan lewat PIN pemilik izin transaksi.batal */
    public function bolehBatal(): bool
    {
        return $this->transaksi && ! $this->transaksi->isDibatalkan();
    }

    public function bayar(): void
    {
        if ($this->transaksi && $this->transaksi->sisaTagihan() > 0) {
            $this->buka = false;
            $this->dispatch('buka-pembayaran', transaksiId: $this->transaksi->id);
        }
    }

    /* ---------------- Struk ---------------- */

    public function bolehCetak(): bool
    {
        return $this->transaksi && $this->transaksi->items->isNotEmpty();
    }

    public function kirimWa(StrukService $struk, WhatsappService $wa): void
    {
        if (! $this->bolehCetak()) {
            return;
        }

        $nomor = preg_replace('/[\s\-().]/', '', $this->nomorWa);

        if (! preg_match('/^(\+?62|0)8\d{7,12}$/', $nomor)) {
            $this->addError('nomorWa', 'Nomor WhatsApp tidak valid. Contoh: 0812xxxxxxx');

            return;
        }

        if ($wa->status()['status'] !== 'terhubung') {
            $this->alert('WhatsApp belum terhubung', 'Minta admin menghubungkan WhatsApp rental di Pengaturan → Notifikasi.', 'warning');

            return;
        }

        $trx = $this->transaksi;

        KirimNotifikasi::antrekan(
            'whatsapp',
            $trx->tenant_id,
            $trx->cabang_id,
            'struk',
            $struk->teksWa($trx),
            referensi: $trx,
            tujuan: '62'.preg_replace('/^(\+?62|0)/', '', $nomor),
        );

        $this->reset(['formWa', 'nomorWa']);
        $this->success('Struk diantrekan ke WhatsApp');
    }

    /** Dipanggil dari tombol konfirmasi: $konfirmasi['reason'] = alasan, $konfirmasi['pin'] = PIN penyetuju */
    public function batalkan(array $konfirmasi = []): void
    {
        if (! $this->transaksi) {
            return;
        }

        $alasan = trim($konfirmasi['reason'] ?? '');

        // Cek alasan sebelum ditambah nama penyetuju, supaya alasan kosong tidak lolos
        if (mb_strlen($alasan) < 5) {
            $this->alert('Tidak bisa dibatalkan', 'Alasan pembatalan wajib diisi (minimal 5 karakter).', 'error');

            return;
        }

        try {
            $penyetuju = app(PinService::class)->setujui(
                $konfirmasi['pin'] ?? null,
                'transaksi.batal',
                app(Tenancy::class)->tenantId()
            );

            if ($penyetuju->id !== auth()->id()) {
                $alasan .= " (disetujui {$penyetuju->name})";
            }

            app(BillingService::class)->batalkan($this->transaksi, auth()->user(), $alasan);
        } catch (BillingException $e) {
            $this->alert('Tidak bisa dibatalkan', $e->getMessage(), 'error');

            return;
        }

        unset($this->transaksi);
        $this->success('Transaksi dibatalkan');
        $this->dispatch('transaksi-berubah');
        $this->dispatch('sesi-berubah');
    }

    public function render()
    {
        return view('livewire.operator.detail-transaksi');
    }
}
