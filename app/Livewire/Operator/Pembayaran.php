<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\PilihMember;
use App\Livewire\Concerns\WithAlert;
use App\Models\Member;
use App\Models\Transaksi;
use App\Services\Billing\BillingService;
use App\Services\Member\MemberService;
use App\Services\Member\PengaturanMember;
use App\Services\Publik\QrisService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class Pembayaran extends Component
{
    use PilihMember, WithAlert;

    public const METODE = [
        'tunai' => 'Tunai',
        'qris' => 'QRIS',
        'transfer' => 'Transfer',
    ];

    public bool $buka = false;

    public ?string $transaksiId = null;

    /** @var array<int, array{metode:string, jumlah:int|null, diterima:int|null, referensi:string}> */
    public array $baris = [];

    public ?int $poinDitukar = null;

    #[On('buka-pembayaran')]
    public function bukaUntuk(string $transaksiId): void
    {
        $this->transaksiId = $transaksiId;
        unset($this->transaksi, $this->sisa);

        $trx = $this->transaksi;

        // Tagihan Rp0 karena potongan (poin/stamp) tetap dibuka supaya bisa dilunasi
        if (! $trx || $trx->isDibatalkan() || $trx->isLunas() || ($trx->sisaTagihan() <= 0 && $trx->total_diskon <= 0)) {
            $this->error('Transaksi ini tidak perlu dibayar');
            $this->dispatch('sesi-berubah');

            return;
        }

        $this->resetValidation();
        $this->resetPilihMember();
        $this->memberId = $trx->member_id;
        $this->jenisPelanggan = $trx->member_id ? 'member' : 'tamu';
        $this->poinDitukar = null;
        $this->baris = $trx->sisaTagihan() > 0 ? [$this->barisBaru('tunai', $trx->sisaTagihan())] : [];
        $this->buka = true;
    }

    /* ---------------- Data ---------------- */

    #[Computed]
    public function transaksi(): ?Transaksi
    {
        return $this->transaksiId
            ? Transaksi::with(['items', 'diskon', 'unit:id,nama'])->find($this->transaksiId)
            : null;
    }

    /** Metode yang bisa dipilih: Saldo hanya untuk transaksi member */
    public function metodeTersedia(): array
    {
        return $this->transaksi?->member_id
            ? self::METODE + ['saldo' => 'Saldo']
            : self::METODE;
    }

    /** Penukaran poin/stamp yang masih aktif di transaksi ini */
    public function penukaran(): Collection
    {
        return $this->transaksi?->diskon
            ->whereIn('jenis', ['poin', 'stamp'])
            ->where('nilai', '>', 0)
            ->values() ?? collect();
    }

    /** QRIS dinamis sesuai nominal (null jika QRIS cabang belum diatur) */
    public function qris(int $nominal): ?string
    {
        return app(QrisService::class)->untukNominal($this->transaksi?->cabang_id, $nominal);
    }

    public function aturanMember(): PengaturanMember
    {
        return app(PengaturanMember::class);
    }

    /* ---------------- Member ---------------- */

    /** Member dipilih / dilepas di kartu pelanggan -> pasang ke transaksi (diskon tier dihitung ulang) */
    protected function memberDipilih(?Member $member): void
    {
        if (! $this->transaksi || $this->transaksi->member_id === $member?->id) {
            return;
        }

        try {
            app(MemberService::class)->pasangMember($this->transaksi, $member);
        } catch (BillingException $e) {
            $this->memberId = $this->transaksi->member_id;
            $this->jenisPelanggan = $this->memberId ? 'member' : 'tamu';
            $this->alert('Tidak bisa mengganti member', $e->getMessage(), 'error');

            return;
        }

        $this->segarkanTagihan();
        $this->dispatch('sesi-berubah');
    }

    public function tukarPoin(): void
    {
        $this->validate(['poinDitukar' => 'required|integer|min:1'], ['poinDitukar.required' => 'Isi jumlah poin.']);

        try {
            $nilai = app(MemberService::class)->tukarPoin($this->transaksi, (int) $this->poinDitukar, auth()->user());
        } catch (BillingException $e) {
            $this->addError('poinDitukar', $e->getMessage());

            return;
        }

        $this->poinDitukar = null;
        $this->segarkanTagihan();
        $this->success('Potongan poin Rp '.number_format($nilai, 0, ',', '.'));
    }

    public function tukarStamp(): void
    {
        try {
            $nilai = app(MemberService::class)->tukarStamp($this->transaksi, auth()->user());
        } catch (BillingException $e) {
            $this->alert('Tidak bisa tukar stamp', $e->getMessage(), 'error');

            return;
        }

        $this->segarkanTagihan();
        $this->success('Potongan stamp Rp '.number_format($nilai, 0, ',', '.'));
    }

    public function batalTukar(string $diskonId): void
    {
        $diskon = $this->penukaran()->firstWhere('id', $diskonId);

        if (! $diskon) {
            return;
        }

        try {
            app(MemberService::class)->batalTukar($diskon, auth()->user());
        } catch (BillingException $e) {
            $this->alert('Gagal', $e->getMessage(), 'error');

            return;
        }

        $this->segarkanTagihan();
        $this->info('Penukaran dibatalkan');
    }

    /** Isi pembayaran pakai saldo: penuh jika cukup, sisanya tunai */
    public function pakaiSaldo(): void
    {
        $saldo = (int) ($this->member?->saldo ?? 0);

        if ($saldo <= 0) {
            $this->error('Saldo member kosong');

            return;
        }

        $this->resetValidation();

        if ($saldo >= $this->sisa) {
            $this->baris = [$this->barisBaru('saldo', $this->sisa)];

            return;
        }

        $this->baris = [$this->barisBaru('saldo', $saldo), $this->barisBaru('tunai', $this->sisa - $saldo)];
    }

    private function segarkanTagihan(): void
    {
        unset($this->transaksi, $this->sisa, $this->member);
        $this->resetValidation();

        if ($this->sisa <= 0) {
            // Seluruh tagihan tertutup potongan: tombol Bayar melunasi Rp0
            $this->baris = [];

            return;
        }

        $this->baris = [$this->barisBaru('tunai', $this->sisa)];
    }

    #[Computed]
    public function sisa(): int
    {
        return $this->transaksi?->sisaTagihan() ?? 0;
    }

    public function totalInput(): int
    {
        return array_sum(array_map(fn ($b) => (int) ($b['jumlah'] ?? 0), $this->baris));
    }

    public function kurang(): int
    {
        return max(0, $this->sisa - $this->totalInput());
    }

    public function kembalian(): int
    {
        $total = 0;

        foreach ($this->baris as $b) {
            if ($b['metode'] === 'tunai' && $b['diterima'] !== null) {
                $total += max(0, (int) $b['diterima'] - (int) $b['jumlah']);
            }
        }

        return $total;
    }

    /** Saran uang diterima: uang pas + pembulatan ke atas pecahan umum */
    public function saranTunai(int $jumlah): array
    {
        if ($jumlah <= 0) {
            return [];
        }

        $kandidat = [$jumlah];

        foreach ([5000, 10000, 20000, 50000, 100000] as $pecahan) {
            $kandidat[] = (int) (ceil($jumlah / $pecahan) * $pecahan);
        }

        return collect($kandidat)->unique()->sort()->values()->take(4)->all();
    }

    /* ---------------- Aksi ---------------- */

    public function tambahBaris(): void
    {
        if (count($this->baris) >= count($this->metodeTersedia())) {
            return;
        }

        $terpakai = array_column($this->baris, 'metode');
        $metode = collect(array_keys($this->metodeTersedia()))->first(fn ($m) => ! in_array($m, $terpakai, true));

        // Baris pertama dikurangi agar total tetap pas
        $sisaBaru = $this->kurang() ?: null;
        $this->baris[] = $this->barisBaru($metode, $sisaBaru);
    }

    public function hapusBaris(int $i): void
    {
        if (count($this->baris) <= 1 || ! isset($this->baris[$i])) {
            return;
        }

        unset($this->baris[$i]);
        $this->baris = array_values($this->baris);

        if (count($this->baris) === 1) {
            $this->baris[0]['jumlah'] = $this->sisa;
        }
    }

    public function setDiterima(int $i, int $nominal): void
    {
        if (isset($this->baris[$i])) {
            $this->baris[$i]['diterima'] = $nominal;
        }
    }

    public function simpan(): void
    {
        $gratis = $this->sisa === 0; // tertutup potongan poin/stamp

        $this->validate([
            'baris' => $gratis ? 'array|max:0' : 'required|array|min:1|max:4',
            'baris.*.metode' => 'required|in:'.implode(',', array_keys($this->metodeTersedia())),
            'baris.*.jumlah' => 'required|integer|min:1',
            'baris.*.diterima' => 'nullable|integer|min:0',
            'baris.*.referensi' => 'nullable|string|max:100',
        ], [
            'baris.*.jumlah.required' => 'Nominal wajib diisi.',
            'baris.*.jumlah.min' => 'Nominal harus lebih dari 0.',
        ]);

        $metode = array_column($this->baris, 'metode');

        if (count($metode) !== count(array_unique($metode))) {
            $this->addError('baris', 'Setiap metode hanya boleh dipakai sekali.');

            return;
        }

        foreach ($this->baris as $i => $b) {
            if ($b['metode'] === 'tunai' && (int) ($b['diterima'] ?? 0) < (int) $b['jumlah']) {
                $this->addError("baris.$i.diterima", 'Uang diterima kurang dari nominal tunai.');

                return;
            }
        }

        if ($this->totalInput() !== $this->sisa) {
            $this->addError('baris', 'Total pembayaran harus sama dengan sisa tagihan.');

            return;
        }

        try {
            $trx = app(BillingService::class)->bayar(
                $this->transaksi,
                auth()->user(),
                array_map(fn ($b) => [
                    'metode' => $b['metode'],
                    'jumlah' => (int) $b['jumlah'],
                    'diterima' => $b['metode'] === 'tunai' ? (int) $b['diterima'] : null,
                    'referensi' => trim($b['referensi'] ?? '') ?: null,
                ], $this->baris)
            );
        } catch (BillingException $e) {
            $this->alert('Pembayaran gagal', $e->getMessage(), 'error');

            return;
        }

        $this->buka = false;
        $this->dispatch('sesi-berubah');

        $teks = $trx->kembalian > 0
            ? 'Kembalian: Rp '.number_format($trx->kembalian, 0, ',', '.')
            : 'Tanpa kembalian.';

        // Dialog sukses dengan tombol lihat struk (pratinjau dulu, cetak manual)
        $this->dispatch('ui:bayar-berhasil', title: 'Pembayaran berhasil', text: $teks, transaksiId: $trx->id);
    }

    private function barisBaru(string $metode, ?int $jumlah): array
    {
        return [
            'metode' => $metode,
            'jumlah' => $jumlah,
            'diterima' => null,
            'referensi' => '',
        ];
    }

    public function render()
    {
        return view('livewire.operator.pembayaran');
    }
}
