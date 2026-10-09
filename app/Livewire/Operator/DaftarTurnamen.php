<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\WithAlert;
use App\Models\Cabang;
use App\Models\Produk;
use App\Models\Turnamen;
use App\Models\TurnamenPertandingan;
use App\Models\TurnamenPeserta;
use App\Models\Unit;
use App\Services\Billing\StokService;
use App\Services\Publik\TurnamenService;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.operator')]
#[Title('Turnamen')]
class DaftarTurnamen extends Component
{
    use WithAlert;

    #[Url(as: 't', except: '')]
    public string $pilihId = '';

    public string $tab = 'peserta';

    // Form turnamen
    public bool $formBuka = false;

    public ?string $editId = null;

    public array $form = [];

    // Peserta baru
    public string $pesertaNama = '';

    public string $pesertaTelepon = '';

    // Bayar
    public bool $bayarBuka = false;

    public ?string $bayarId = null;

    public string $bayarMetode = 'tunai';

    public ?int $bayarDiterima = null;

    /** Skor sementara per pertandingan: [id => [a, b]] */
    public array $skor = [];

    public array $unitMain = [];

    /* ---------------- Data ---------------- */

    #[Computed]
    public function daftar(): Collection
    {
        return Turnamen::query()->withCount(['peserta as jumlah_peserta' => fn ($q) => $q->where('status', '!=', 'batal')])
            ->orderByRaw("FIELD(status, 'berjalan', 'pendaftaran', 'draft', 'selesai', 'batal')")
            ->orderByDesc('mulai_pada')->limit(50)->get();
    }

    #[Computed]
    public function terpilih(): ?Turnamen
    {
        return $this->pilihId !== '' ? Turnamen::find($this->pilihId) : null;
    }

    #[Computed]
    public function peserta(): Collection
    {
        return $this->terpilih?->peserta()->where('status', '!=', 'batal')->orderBy('created_at')->get() ?? collect();
    }

    /** Bagan / klasemen per bagian (semua format) */
    #[Computed]
    public function bagian(): array
    {
        return $this->terpilih ? app(TurnamenService::class)->tampilan($this->terpilih) : [];
    }

    #[Computed]
    public function keuangan(): array
    {
        return app(TurnamenService::class)->keuangan($this->terpilih);
    }

    /** Produk aktif untuk bonus bundling */
    #[Computed]
    public function produkList(): Collection
    {
        return Produk::query()->where('is_active', true)->orderBy('nama')->get(['id', 'nama', 'lacak_stok']);
    }

    /** Hitungan cepat di form: uang masuk bila kuota penuh, modal bonus, sisa setelah hadiah */
    #[Computed]
    public function ringkasForm(): array
    {
        $peserta = max(0, (int) ($this->form['kuota'] ?? 0));
        $biaya = max(0, (int) ($this->form['biaya_daftar'] ?? 0));
        $hadiah = max(0, (int) ($this->form['total_hadiah'] ?? 0));
        $produk = ($this->form['bonus_produk_id'] ?? null) ? Produk::find($this->form['bonus_produk_id']) : null;
        $qty = $produk ? max(1, (int) ($this->form['bonus_qty'] ?? 1)) : 0;
        $hpp = $produk?->lacak_stok ? (int) app(StokService::class)->kunci($produk, $this->cabang()->id)->hpp_rata : 0;

        $masuk = $peserta * $biaya;
        $modal = $peserta * $qty * $hpp;
        $bersih = $masuk - $modal;

        return [
            'peserta' => $peserta, 'biaya' => $biaya, 'masuk' => $masuk, 'modal_bonus' => $modal,
            'label_bonus' => $produk ? ($qty > 1 ? "{$qty}x " : '').$produk->nama.' ('.$peserta * $qty.' × Rp'.number_format($hpp, 0, ',', '.').')' : null,
            'bersih' => $bersih, 'hadiah' => $hadiah, 'sisa' => $bersih - $hadiah,
            'saran' => collect([50, 60, 70])->mapWithKeys(fn ($p) => [$p => (int) (floor($bersih * $p / 100 / 1000) * 1000)])->all(),
        ];
    }

    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->aktif()->urut()->get(['id', 'nama']);
    }

    public function juara(): array
    {
        return $this->terpilih ? app(TurnamenService::class)->juara($this->terpilih) : [];
    }

    /* ---------------- Turnamen ---------------- */

    public function pilih(string $id): void
    {
        $this->pilihId = $id;
        $this->tab = Turnamen::find($id)?->status === 'pendaftaran' ? 'peserta' : 'bagan';
        $this->segarkan();
    }

    public function tambah(): void
    {
        $this->izin();
        $this->resetValidation();
        $this->editId = null;
        $this->form = [
            'nama' => '', 'game' => '', 'mulai_pada' => now()->addWeek()->setTime(19, 0)->format('Y-m-d\TH:i'),
            'format' => 'gugur', 'jumlah_grup' => 4, 'lolos_per_grup' => 2, 'putaran' => 1,
            'biaya_daftar' => 0, 'kuota' => 16, 'bonus_produk_id' => '', 'bonus_qty' => 1,
            'hadiah' => '', 'total_hadiah' => 0, 'aturan' => '', 'daftar_online' => true,
        ];
        $this->formBuka = true;
    }

    public function ubah(): void
    {
        $this->izin();
        $t = $this->terpilih;

        if (! $t) {
            return;
        }

        $this->resetValidation();
        $this->editId = $t->id;
        $this->form = [
            'nama' => $t->nama, 'game' => $t->game, 'mulai_pada' => $t->mulai_pada->format('Y-m-d\TH:i'),
            'format' => $t->format, 'jumlah_grup' => $t->jumlah_grup ?? 4, 'lolos_per_grup' => $t->lolos_per_grup ?? 2, 'putaran' => $t->putaran,
            'biaya_daftar' => $t->biaya_daftar, 'kuota' => $t->kuota, 'bonus_produk_id' => (string) $t->bonus_produk_id, 'bonus_qty' => max(1, $t->bonus_qty),
            'hadiah' => (string) $t->hadiah, 'total_hadiah' => $t->total_hadiah,
            'aturan' => (string) $t->aturan, 'daftar_online' => $t->daftar_online,
        ];
        $this->formBuka = true;
    }

    public function simpan(TurnamenService $service): void
    {
        $this->izin();
        $this->validate([
            'form.nama' => 'required|string|min:3|max:120',
            'form.game' => 'required|string|max:100',
            'form.mulai_pada' => 'required|date',
            'form.format' => 'required|in:'.implode(',', array_keys(Turnamen::FORMAT)),
            'form.jumlah_grup' => 'nullable|integer|min:2|max:8',
            'form.lolos_per_grup' => 'nullable|integer|min:1|max:2',
            'form.putaran' => 'nullable|integer|min:1|max:2',
            'form.biaya_daftar' => 'nullable|integer|min:0',
            'form.bonus_produk_id' => 'nullable|string',
            'form.bonus_qty' => 'nullable|integer|min:1|max:20',
            'form.total_hadiah' => 'nullable|integer|min:0',
            'form.kuota' => 'required|integer|min:2|max:128',
            'form.hadiah' => 'nullable|string|max:1000',
            'form.aturan' => 'nullable|string|max:3000',
        ], ['form.nama.required' => 'Nama turnamen wajib diisi.', 'form.game.required' => 'Game wajib diisi.']);

        try {
            $t = $service->simpan($this->cabang(), $this->form, $this->editId ? Turnamen::findOrFail($this->editId) : null);
        } catch (BillingException $e) {
            $this->addError('form.nama', $e->getMessage());

            return;
        }

        $this->formBuka = false;
        $this->pilih($t->id);
        $this->success('Turnamen disimpan');
    }

    public function mulaiTurnamen(TurnamenService $service): void
    {
        $this->izin();

        try {
            $service->mulai($this->terpilih);
        } catch (BillingException $e) {
            $this->alert('Belum bisa dimulai', $e->getMessage(), 'error');

            return;
        }

        $this->tab = 'bagan';
        $this->segarkan();
        $this->success('Bagan dibuat, turnamen dimulai');
    }

    public function batalTurnamen(): void
    {
        $this->izin();
        $this->terpilih?->update(['status' => 'batal']);
        $this->segarkan();
    }

    /* ---------------- Peserta ---------------- */

    public function tambahPeserta(TurnamenService $service): void
    {
        $this->izin();
        $this->validate(['pesertaNama' => 'required|string|min:2|max:100', 'pesertaTelepon' => 'required|string|min:9|max:20'], [
            'pesertaNama.required' => 'Nama / gamer tag wajib diisi.', 'pesertaTelepon.required' => 'Nomor HP wajib diisi.',
        ]);

        try {
            $p = $service->daftar($this->terpilih, $this->pesertaNama, $this->pesertaTelepon, 'kasir');
        } catch (BillingException $e) {
            $this->addError('pesertaNama', $e->getMessage());

            return;
        }

        $this->reset(['pesertaNama', 'pesertaTelepon']);
        $this->segarkan();
        $this->success(__(':nama terdaftar', ['nama' => $p->nama]));

        if ($p->status === 'terdaftar') {
            $this->bukaBayar($p->id);
        }
    }

    public function unggulan(string $id, $nilai): void
    {
        $this->izin();
        TurnamenPeserta::whereKey($id)->update(['unggulan' => ((int) $nilai) ?: null]);
    }

    public function bukaBayar(string $id): void
    {
        $this->resetValidation();
        $this->bayarId = $id;
        $this->bayarBuka = true;
        $this->bayarMetode = 'tunai';
        $this->bayarDiterima = $this->terpilih?->biaya_daftar;
    }

    public function bayar(TurnamenService $service): void
    {
        $this->izin();
        $p = TurnamenPeserta::with('turnamen')->find($this->bayarId);

        if (! $p) {
            return;
        }

        if ($this->bayarMetode === 'tunai' && (int) $this->bayarDiterima < $p->turnamen->biaya_daftar) {
            $this->addError('bayarDiterima', 'Uang diterima kurang.');

            return;
        }

        try {
            $trx = $service->bayar($p, auth()->user(), $this->bayarMetode, $this->bayarMetode === 'tunai' ? (int) $this->bayarDiterima : null);
        } catch (BillingException $e) {
            $this->alert('Pembayaran gagal', $e->getMessage(), 'error');

            return;
        }

        $this->bayarId = null;
        $this->bayarBuka = false;
        $this->segarkan();
        $this->dispatch('ui:bayar-berhasil', title: __('Pendaftaran lunas'), text: $trx->kembalian > 0 ? __('Kembalian :nominal', ['nominal' => 'Rp '.number_format($trx->kembalian, 0, ',', '.')]) : $p->nama, transaksiId: $trx->id);
    }

    public function hapusPeserta(string $id, TurnamenService $service): void
    {
        $this->izin();

        try {
            $service->batalPeserta(TurnamenPeserta::findOrFail($id));
        } catch (BillingException $e) {
            $this->alert('Tidak bisa dihapus', $e->getMessage(), 'error');

            return;
        }

        $this->segarkan();
    }

    /* ---------------- Pertandingan ---------------- */

    public function main(string $id, TurnamenService $service): void
    {
        $this->izin();

        try {
            $service->mulaiMain(TurnamenPertandingan::findOrFail($id), $this->unitMain[$id] ?? null);
        } catch (BillingException $e) {
            $this->error($e->getMessage());

            return;
        }

        $this->segarkan();
    }

    public function simpanSkor(string $id, TurnamenService $service): void
    {
        $this->izin();
        [$a, $b] = [$this->skor[$id]['a'] ?? null, $this->skor[$id]['b'] ?? null];

        if (! is_numeric($a) || ! is_numeric($b)) {
            $this->error('Isi skor kedua pemain');

            return;
        }

        try {
            $service->hasil(TurnamenPertandingan::findOrFail($id), (int) $a, (int) $b);
        } catch (BillingException $e) {
            $this->alert('Skor tidak disimpan', $e->getMessage(), 'error');

            return;
        }

        unset($this->skor[$id]);
        $this->segarkan();
        $this->success('Skor disimpan');
    }

    public function segarkan(): void
    {
        unset($this->daftar, $this->terpilih, $this->peserta, $this->bagian, $this->keuangan);
    }

    private function izin(): void
    {
        abort_unless(auth()->user()->can('turnamen.kelola'), 403);
    }

    private function cabang(): Cabang
    {
        return Cabang::findOrFail(app(Tenancy::class)->cabangId());
    }

    public function render()
    {
        return view('livewire.operator.daftar-turnamen');
    }
}
