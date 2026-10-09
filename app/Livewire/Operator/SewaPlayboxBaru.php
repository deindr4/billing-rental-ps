<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\UnggahSementara;
use App\Livewire\Concerns\WithAlert;
use App\Models\Penyewa;
use App\Models\Playbox;
use App\Services\Playbox\PlayboxService;
use App\Support\Koordinat;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Sewa Playbox baru (bayar di muka), 4 langkah: penyewa (identitas, lokasi, foto & KTP) → unit & durasi →
 * jaminan, checklist & foto kondisi keluar → syarat & tanda tangan. Selesai → dialog Pembayaran.
 */
#[Layout('layouts.operator')]
#[Title('Sewa Playbox Baru')]
class SewaPlayboxBaru extends Component
{
    use UnggahSementara, WithAlert, WithFileUploads;

    public int $langkah = 1;

    // 1. Penyewa
    public string $cariHp = '';

    public ?string $penyewaId = null;

    public array $penyewa = ['nama' => '', 'telepon' => '', 'nik' => '', 'alamat' => '', 'jenis_tempat' => 'rumah', 'koordinat' => ''];

    public $fotoPenyewa = null;

    public $fotoKtp = null;

    public bool $setujuDaftarHitam = false;

    // 2. Unit & durasi
    public ?string $playboxId = null;

    public string $satuan = 'hari';

    public int $jumlah = 1;

    // 3. Jaminan & checklist
    public array $jaminan = ['identitas' => true, 'deposit' => false, 'barang' => false];

    public string $identitasKet = 'KTP asli';

    public string $identitasNomor = '';

    public ?int $deposit = null;

    public string $barangKet = '';

    public $fotoBarang = null;

    public array $checklist = [];

    public array $fotoKondisi = [];

    // 4. Syarat & tanda tangan
    public string $tandaTangan = '';

    public bool $setujuSyarat = false;

    public string $catatan = '';

    #[Computed]
    public function unitTersedia(): Collection
    {
        return Playbox::aktif()->where('status', 'tersedia')->orderBy('urutan')->orderBy('kode')->get();
    }

    #[Computed]
    public function unit(): ?Playbox
    {
        return $this->unitTersedia->firstWhere('id', $this->playboxId);
    }

    #[Computed]
    public function penyewaLama(): ?Penyewa
    {
        return $this->penyewaId ? Penyewa::query()->find($this->penyewaId) : null;
    }

    public function harga(): int
    {
        return $this->unit ? $this->unit->harga($this->satuan) * max(1, $this->jumlah) : 0;
    }

    public function jatuhTempo(): ?string
    {
        return $this->unit && $this->unit->harga($this->satuan) > 0
            ? PlayboxService::jatuhTempo(now(), $this->satuan, max(1, $this->jumlah))->translatedFormat('D, d M Y H:i') : null;
    }

    /** HP & NIK hanya angka (NIK maks. 16 digit) */
    public function updatedPenyewa($nilai, string $kunci): void
    {
        if (in_array($kunci, ['telepon', 'nik'], true)) {
            $angka = preg_replace('/\D/', '', (string) $nilai);
            $this->penyewa[$kunci] = $kunci === 'nik' ? substr($angka, 0, 16) : substr($angka, 0, 15);
        }
    }

    public function updatedCariHp(): void
    {
        $this->cariHp = substr(preg_replace('/\D/', '', $this->cariHp), 0, 15);
    }

    /** Cari penyewa lama dari nomor HP → data terisi otomatis */
    public function cari(): void
    {
        $hp = Penyewa::rapikanTelepon($this->cariHp);
        $p = strlen($hp) >= 9 ? Penyewa::query()->where('telepon', $hp)->first() : null;
        unset($this->penyewaLama);

        if (! $p) {
            $this->penyewaId = null;
            $this->penyewa['telepon'] = $this->cariHp;
            $this->info('Penyewa baru — lengkapi datanya');

            return;
        }

        $this->penyewaId = $p->id;
        $this->penyewa = ['nama' => $p->nama, 'telepon' => $p->telepon, 'nik' => '', 'alamat' => (string) $p->alamat,
            'jenis_tempat' => $p->jenis_tempat, 'koordinat' => $p->lat ? "{$p->lat}, {$p->lng}" : ''];
    }

    public function urlMaps(): ?string
    {
        $k = Koordinat::urai($this->penyewa['koordinat'] ?? '');

        return $k ? Koordinat::urlMaps(...$k) : null;
    }

    public function pilihUnit(string $id): void
    {
        $this->playboxId = $id;
        unset($this->unit);
        $u = $this->unit;

        if (! $u) {
            return;
        }

        $tarif = $u->tarif();
        $this->satuan = array_key_exists($this->satuan, $tarif) ? $this->satuan : (array_key_first($tarif) ?? 'hari');
        $this->deposit = $u->deposit_saran ?: $this->deposit;
        $this->checklist = array_map(fn ($k) => ['nama' => $k['nama'], 'jumlah' => (int) ($k['jumlah'] ?? 1), 'kondisi' => 'baik'], $u->daftarKelengkapan());
    }

    public function lanjut(): void
    {
        $this->resetValidation();

        if ($this->langkah === 1) {
            $this->validate([
                'penyewa.nama' => 'required|string|min:2|max:100',
                'penyewa.telepon' => 'required|digits_between:9,15',
                'penyewa.nik' => 'nullable|digits:16',
                'penyewa.alamat' => 'required|string|max:500',
                'fotoPenyewa' => $this->penyewaLama?->foto ? 'nullable|file|max:10240' : 'required|file|max:10240',
                'fotoKtp' => $this->penyewaLama?->foto_ktp ? 'nullable|file|max:10240' : 'required|file|max:10240',
            ], [
                'fotoPenyewa.required' => 'Foto penyewa wajib.', 'fotoKtp.required' => 'Foto KTP wajib.',
                'penyewa.alamat.required' => 'Alamat rumah / kost wajib.',
                'penyewa.telepon.digits_between' => 'Nomor HP 9–15 angka.',
                'penyewa.nik.digits' => 'NIK harus 16 angka.',
            ]);

            if (filled($this->penyewa['koordinat']) && ! $this->urlMaps()) {
                $this->addError('penyewa.koordinat', 'Koordinat / link Google Maps tidak dikenali.');

                return;
            }

            if ($this->penyewaLama?->daftar_hitam && ! $this->setujuDaftarHitam) {
                $this->addError('setujuDaftarHitam', 'Penyewa ada di daftar hitam. Hanya supervisor/owner yang bisa melanjutkan.');

                return;
            }
        }

        if ($this->langkah === 2 && (! $this->unit || $this->harga() <= 0 || $this->jumlah < 1)) {
            $this->addError('playboxId', 'Pilih unit, satuan & lama sewa.');

            return;
        }

        if ($this->langkah === 3 && ! in_array(true, $this->jaminan, true)) {
            $this->addError('jaminan', 'Pilih minimal satu jaminan.');

            return;
        }

        $this->langkah = min(4, $this->langkah + 1);
    }

    public function kembali(): void
    {
        $this->langkah = max(1, $this->langkah - 1);
    }

    public function simpan(PlayboxService $layanan)
    {
        $this->resetValidation();

        if (! $this->setujuSyarat || blank($this->tandaTangan)) {
            $this->addError('tandaTangan', 'Penyewa menyetujui syarat & tanda tangan di layar.');

            return;
        }

        $tenantId = auth()->user()->tenant_id;

        try {
            $penyewa = $layanan->simpanPenyewa($this->penyewa, $tenantId, $this->keFile($this->fotoPenyewa), $this->keFile($this->fotoKtp), $this->penyewaLama);

            $jaminan = [];
            if ($this->jaminan['identitas']) {
                $jaminan[] = ['jenis' => 'identitas', 'keterangan' => $this->identitasKet, 'nomor' => $this->identitasNomor];
            }
            if ($this->jaminan['barang']) {
                $jaminan[] = ['jenis' => 'barang', 'keterangan' => $this->barangKet, 'foto' => $this->keFile($this->fotoBarang)];
            }
            if ($this->jaminan['deposit']) {
                $jaminan[] = ['jenis' => 'deposit', 'keterangan' => 'Uang deposit', 'nomor' => ''];
            }

            $sewa = $layanan->sewa($this->unit, $penyewa, auth()->user(), [
                'satuan' => $this->satuan,
                'jumlah' => $this->jumlah,
                'jaminan' => $jaminan,
                'deposit' => $this->jaminan['deposit'] ? (int) $this->deposit : 0,
                'checklist' => $this->checklist,
                'foto_kondisi' => $this->keFileBanyak($this->fotoKondisi),
                'tanda_tangan' => $this->tandaTangan,
                'catatan' => $this->catatan,
                'setuju_daftar_hitam' => $this->setujuDaftarHitam,
            ]);
        } catch (BillingException $e) {
            $this->alert('Tidak bisa menyewakan', $e->getMessage(), 'error');

            return;
        } catch (\RuntimeException) {
            $this->alert('Foto tidak terbaca', 'Ambil ulang foto (format gambar).', 'error');

            return;
        } finally {
            $this->hapusSementara();
        }

        $layanan->kirimRingkasan($sewa);
        $this->flashSuccess("Sewa {$sewa->nomor} dibuat · lanjutkan pembayaran");

        return $this->redirectRoute('playbox', ['bayar' => $sewa->transaksi_id], navigate: true);
    }

    public function render()
    {
        return view('livewire.operator.sewa-playbox-baru', [
            'syarat' => (string) \App\Models\Pengaturan::ambil('playbox.syarat', PlayboxService::SYARAT_DEFAULT),
        ]);
    }
}
