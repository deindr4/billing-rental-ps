<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\WithAlert;
use App\Models\Cabang;
use App\Models\Member as MemberModel;
use App\Models\MemberMutasi;
use App\Services\Billing\ShiftService;
use App\Services\Member\MemberService;
use App\Services\Member\PengaturanMember;
use App\Services\PinService;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.operator')]
#[Title('Member')]
class Member extends Component
{
    use WithAlert;

    public const NOMINAL_TOPUP = [20_000, 50_000, 100_000, 200_000];

    public const AKUN = [
        '' => 'Semua',
        'saldo' => 'Saldo',
        'poin' => 'Poin',
        'stamp' => 'Stamp',
    ];

    #[Url(as: 'q', except: '')]
    public string $cari = '';

    #[Url(except: '')]
    public string $tier = '';

    #[Url(as: 'm', except: '')]
    public string $pilihId = '';

    public string $akun = '';

    // Form member (baru / ubah)
    public bool $formBuka = false;

    public ?string $editId = null;

    public array $form = ['nama' => '', 'telepon' => '', 'email' => '', 'tanggal_lahir' => '', 'catatan' => '', 'is_active' => true];

    // Top up
    public bool $topupBuka = false;

    public ?int $topupJumlah = null;

    public string $topupMetode = 'tunai';

    public ?int $topupDiterima = null;

    public string $topupReferensi = '';

    // Koreksi
    public bool $koreksiBuka = false;

    public string $koreksiAkun = 'poin';

    public ?int $koreksiJumlah = null;

    public string $koreksiAlasan = '';

    /* ---------------- Data ---------------- */

    #[Computed]
    public function daftar(): Collection
    {
        return MemberModel::query()
            ->cari($this->cari)
            ->when($this->tier !== '', fn ($q) => $q->where('tier', $this->tier))
            ->orderByDesc('terakhir_kunjungan')
            ->orderBy('nama')
            ->limit(60)
            ->get();
    }

    #[Computed]
    public function jumlahMember(): int
    {
        return MemberModel::query()->count();
    }

    #[Computed]
    public function terpilih(): ?MemberModel
    {
        return $this->pilihId !== '' ? MemberModel::with('cabang:id,nama')->find($this->pilihId) : null;
    }

    #[Computed]
    public function riwayat(): Collection
    {
        if (! $this->terpilih) {
            return collect();
        }

        return MemberMutasi::query()
            ->with(['transaksi:id,nomor', 'user:id,name', 'cabang:id,nama'])
            ->where('member_id', $this->terpilih->id)
            ->when($this->akun !== '', fn ($q) => $q->where('akun', $this->akun))
            ->when($this->akun === '', fn ($q) => $q->where('akun', '!=', MemberMutasi::AKUN_BELANJA))
            ->latest()
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function aturan(): PengaturanMember
    {
        return app(PengaturanMember::class);
    }

    public function infoTier(): array
    {
        $m = $this->terpilih;
        $service = app(MemberService::class);

        return [
            'diskon' => $m ? $service->diskonPersen($m) : 0,
            'berikutnya' => $m ? $service->tierBerikutnya($m) : null,
        ];
    }

    public function bonusTopUp(): int
    {
        return app(MemberService::class)->bonusUntuk((int) $this->topupJumlah);
    }

    /* ---------------- Pilih ---------------- */

    public function pilih(string $id): void
    {
        $this->pilihId = $id;
        $this->akun = '';
        unset($this->terpilih, $this->riwayat);
    }

    public function tutupDetail(): void
    {
        $this->pilihId = '';
        unset($this->terpilih, $this->riwayat);
    }

    public function updatedAkun(): void
    {
        unset($this->riwayat);
    }

    /* ---------------- Daftar / ubah ---------------- */

    public function formBaru(): void
    {
        $this->izin('member.kelola');
        $this->resetValidation();
        $this->editId = null;
        $this->form = ['nama' => '', 'telepon' => preg_replace('/\D/', '', $this->cari) ?: '', 'email' => '', 'tanggal_lahir' => '', 'catatan' => '', 'is_active' => true];
        $this->formBuka = true;
    }

    public function formUbah(): void
    {
        $this->izin('member.kelola');
        $m = $this->terpilih;

        if (! $m) {
            return;
        }

        $this->resetValidation();
        $this->editId = $m->id;
        $this->form = [
            'nama' => $m->nama,
            'telepon' => $m->telepon,
            'email' => (string) $m->email,
            'tanggal_lahir' => $m->tanggal_lahir?->toDateString() ?? '',
            'catatan' => (string) $m->catatan,
            'is_active' => $m->is_active,
        ];
        $this->formBuka = true;
    }

    public function simpanForm(MemberService $service): void
    {
        $this->izin('member.kelola');

        $this->validate([
            'form.nama' => 'required|string|min:2|max:100',
            'form.telepon' => 'required|string|min:9|max:20',
            'form.email' => 'nullable|email|max:150',
            'form.tanggal_lahir' => 'nullable|date|before:today',
            'form.catatan' => 'nullable|string|max:500',
        ], [
            'form.nama.required' => 'Nama wajib diisi.',
            'form.telepon.required' => 'Nomor HP wajib diisi.',
            'form.telepon.min' => 'Nomor HP terlalu pendek.',
        ]);

        $data = array_map(fn ($v) => is_string($v) ? (trim($v) ?: null) : $v, $this->form);

        try {
            if ($this->editId) {
                $member = $service->ubah(MemberModel::findOrFail($this->editId), $data);
                $this->success('Data member disimpan');
            } else {
                $tenancy = app(Tenancy::class);
                $member = $service->daftar($tenancy->tenantId(), $tenancy->cabangId(), $data);
                $this->success("Member {$member->kode} terdaftar");
            }
        } catch (BillingException $e) {
            $this->addError('form.telepon', $e->getMessage());

            return;
        }

        $this->formBuka = false;
        $this->pilih($member->id);
        unset($this->daftar, $this->jumlahMember);
    }

    /* ---------------- Top up ---------------- */

    public function bukaTopUp(): void
    {
        $this->izin('member.topup');

        if (! app(ShiftService::class)->aktif(auth()->user(), app(Tenancy::class)->cabangId())) {
            $this->alert('Shift belum dibuka', 'Buka kas dulu sebelum menerima top up.', 'warning');

            return;
        }

        $this->resetValidation();
        $this->reset(['topupJumlah', 'topupDiterima', 'topupReferensi']);
        $this->topupMetode = 'tunai';
        $this->topupBuka = true;
    }

    public function setTopUp(int $nominal): void
    {
        $this->topupJumlah = $nominal;
        $this->topupDiterima = $this->topupMetode === 'tunai' ? $nominal : null;
    }

    public function simpanTopUp(MemberService $service): void
    {
        $this->izin('member.topup');

        $this->validate([
            'topupJumlah' => 'required|integer|min:1000|max:10000000',
            'topupMetode' => 'required|in:tunai,qris,transfer',
            'topupDiterima' => 'nullable|required_if:topupMetode,tunai|integer|gte:topupJumlah',
            'topupReferensi' => 'nullable|string|max:100',
        ], [
            'topupJumlah.required' => 'Isi nominal top up.',
            'topupDiterima.required_if' => 'Isi uang diterima.',
            'topupDiterima.gte' => 'Uang diterima kurang dari nominal.',
        ]);

        try {
            $trx = $service->topUp(
                $this->terpilih,
                auth()->user(),
                Cabang::findOrFail(app(Tenancy::class)->cabangId()),
                (int) $this->topupJumlah,
                $this->topupMetode,
                $this->topupMetode === 'tunai' ? (int) $this->topupDiterima : null,
                trim($this->topupReferensi) ?: null,
            );
        } catch (BillingException $e) {
            $this->alert('Top up gagal', $e->getMessage(), 'error');

            return;
        }

        $this->topupBuka = false;
        unset($this->terpilih, $this->riwayat);

        $teks = 'Saldo sekarang Rp '.number_format($this->terpilih->saldo, 0, ',', '.')
            .($trx->kembalian > 0 ? ' · Kembalian Rp '.number_format($trx->kembalian, 0, ',', '.') : '');

        $this->dispatch('ui:bayar-berhasil', title: 'Top up berhasil', text: $teks, transaksiId: $trx->id);
    }

    /* ---------------- Koreksi ---------------- */

    public function bukaKoreksi(): void
    {
        $this->resetValidation();
        $this->reset(['koreksiJumlah', 'koreksiAlasan']);
        $this->koreksiAkun = 'poin';
        $this->koreksiBuka = true;
    }

    /** Dipanggil tombol konfirmasi (PIN penyetuju) */
    public function simpanKoreksi(array $konfirmasi = []): void
    {
        $this->validate([
            'koreksiAkun' => 'required|in:saldo,poin,stamp',
            'koreksiJumlah' => 'required|integer|not_in:0|between:-10000000,10000000',
            'koreksiAlasan' => 'required|string|min:5|max:200',
        ], [
            'koreksiJumlah.required' => 'Isi jumlah koreksi (minus untuk mengurangi).',
            'koreksiJumlah.not_in' => 'Jumlah tidak boleh 0.',
            'koreksiAlasan.required' => 'Alasan wajib diisi.',
            'koreksiAlasan.min' => 'Alasan minimal 5 karakter.',
        ]);

        try {
            $penyetuju = app(PinService::class)->setujui($konfirmasi['pin'] ?? null, 'member.koreksi', app(Tenancy::class)->tenantId());
            app(MemberService::class)->koreksi($this->terpilih, $this->koreksiAkun, (int) $this->koreksiJumlah, $this->koreksiAlasan, auth()->user(), $penyetuju);
        } catch (BillingException $e) {
            $this->alert('Koreksi gagal', $e->getMessage(), 'error');

            return;
        }

        $this->koreksiBuka = false;
        unset($this->terpilih, $this->riwayat);
        $this->success('Koreksi dicatat');
    }

    private function izin(string $izin): void
    {
        abort_unless(auth()->user()->can($izin), 403);
    }

    public function render()
    {
        return view('livewire.operator.member');
    }
}
