<?php

namespace App\Livewire\Concerns;

use App\Exceptions\BillingException;
use App\Models\Member;
use App\Services\Member\MemberService;
use App\Services\Member\PengaturanMember;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Pilih / cari / daftar cepat member (Stitch 08 "Data Pelanggan").
 * View: livewire.operator.partials.pilih-member
 * Komponen boleh override memberDipilih() untuk aksi tambahan.
 */
trait PilihMember
{
    /** tamu | member */
    public string $jenisPelanggan = 'tamu';

    public string $cariMember = '';

    public ?string $memberId = null;

    public bool $daftarBaru = false;

    public string $daftarNama = '';

    public string $daftarTelepon = '';

    protected function resetPilihMember(): void
    {
        $this->reset(['jenisPelanggan', 'cariMember', 'memberId', 'daftarBaru', 'daftarNama', 'daftarTelepon']);
        unset($this->hasilMember, $this->member);
    }

    #[Computed]
    public function programMemberAktif(): bool
    {
        return app(PengaturanMember::class)->aktif();
    }

    #[Computed]
    public function hasilMember(): Collection
    {
        if ($this->memberId || mb_strlen(trim($this->cariMember)) < 2) {
            return collect();
        }

        return Member::query()->aktif()->cari($this->cariMember)->orderBy('nama')->limit(6)->get();
    }

    #[Computed]
    public function member(): ?Member
    {
        return $this->memberId ? Member::find($this->memberId) : null;
    }

    /** Info kartu member: diskon tier & tier berikutnya */
    public function infoMember(): array
    {
        $m = $this->member;

        if (! $m) {
            return [];
        }

        $service = app(MemberService::class);

        return [
            'diskon' => $service->diskonPersen($m),
            'berikutnya' => $service->tierBerikutnya($m),
        ];
    }

    public function updatedJenisPelanggan(): void
    {
        if ($this->jenisPelanggan === 'tamu') {
            $this->lepasMember();
        }
    }

    public function updatedCariMember(): void
    {
        unset($this->hasilMember);
    }

    public function pilihMember(string $id): void
    {
        $member = Member::query()->aktif()->find($id);

        if (! $member) {
            $this->error('Member tidak ditemukan');

            return;
        }

        $this->memberId = $member->id;
        $this->jenisPelanggan = 'member';
        $this->cariMember = '';
        $this->daftarBaru = false;
        unset($this->member, $this->hasilMember);

        $this->memberDipilih($member);
    }

    public function lepasMember(): void
    {
        $this->memberId = null;
        unset($this->member);
        $this->memberDipilih(null);
    }

    public function bukaDaftarBaru(): void
    {
        $angka = preg_replace('/\D/', '', $this->cariMember);
        $this->daftarTelepon = strlen($angka) >= 6 ? $angka : '';
        $this->daftarNama = $this->daftarTelepon === '' ? trim($this->cariMember) : '';
        $this->daftarBaru = true;
    }

    public function simpanDaftarBaru(): void
    {
        abort_unless(auth()->user()->can('member.kelola'), 403);

        $this->validate([
            'daftarNama' => 'required|string|min:2|max:100',
            'daftarTelepon' => 'required|string|min:9|max:20',
        ], [
            'daftarNama.required' => 'Nama wajib diisi.',
            'daftarTelepon.required' => 'Nomor HP wajib diisi.',
            'daftarTelepon.min' => 'Nomor HP terlalu pendek.',
        ]);

        $tenancy = app(Tenancy::class);

        try {
            $member = app(MemberService::class)->daftar($tenancy->tenantId(), $tenancy->cabangId(), [
                'nama' => $this->daftarNama,
                'telepon' => $this->daftarTelepon,
            ]);
        } catch (BillingException $e) {
            $this->addError('daftarTelepon', $e->getMessage());

            return;
        }

        $this->success("Member {$member->kode} terdaftar");
        $this->pilihMember($member->id);
    }

    /** Hook setelah member dipilih/dilepas */
    protected function memberDipilih(?Member $member): void {}
}
