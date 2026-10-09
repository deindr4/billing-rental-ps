<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\WithAlert;
use App\Models\Absensi;
use App\Models\Karyawan;
use App\Services\Karyawan\AbsensiService;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Absen masuk / pulang di tablet kasir: pilih nama → PIN → foto selfie.
 * Foto lewat <input capture> (kamera bawaan), karena tablet membuka aplikasi lewat http:// LAN
 * dan akses kamera langsung dari browser hanya diizinkan di HTTPS.
 */
#[Layout('layouts.operator')]
#[Title('Absen')]
class Absen extends Component
{
    use WithAlert, WithFileUploads;

    #[Url(as: 'k', except: '')]
    public string $karyawanId = '';

    public string $pin = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $foto = null;

    /** Karyawan aktif cabang ini (atau tanpa cabang utama) */
    #[Computed]
    public function daftar(): Collection
    {
        $cabangId = app(Tenancy::class)->cabangId();

        $karyawan = Karyawan::aktif()
            ->where(fn ($q) => $q->where('cabang_id', $cabangId)->orWhereNull('cabang_id'))
            ->orderBy('nama')->get();

        $buka = Absensi::query()->whereIn('karyawan_id', $karyawan->pluck('id'))->whereNull('pulang_pada')
            ->get()->keyBy('karyawan_id');

        return $karyawan->map(fn (Karyawan $k) => ['k' => $k, 'buka' => $buka->get($k->id)]);
    }

    #[Computed]
    public function dipilih(): ?array
    {
        return $this->daftar->first(fn ($d) => $d['k']->id === $this->karyawanId);
    }

    public function pilih(string $id): void
    {
        $this->karyawanId = $id;
        $this->reset(['pin', 'foto']);
        $this->resetValidation();
        unset($this->dipilih);
    }

    public function masuk(AbsensiService $absensi): void
    {
        $this->simpan(fn (Karyawan $k, string $foto) => $absensi->masuk($k, $this->pin, $foto, app(Tenancy::class)->cabangId()), 'masuk');
    }

    public function pulang(AbsensiService $absensi): void
    {
        $this->simpan(fn (Karyawan $k, string $foto) => $absensi->pulang($k, $this->pin, $foto), 'pulang');
    }

    private function simpan(callable $aksi, string $jenis): void
    {
        $this->validate([
            'pin' => 'required|string',
            // Isi file dicek saat dikompres ke WebP (bukan gambar → ditolak)
            'foto' => 'required|file|max:10240',
        ], [
            'pin.required' => 'Ketik PIN Anda.',
            'foto.required' => 'Ambil foto selfie dulu.',
        ]);

        $k = $this->dipilih['k'] ?? null;

        if (! $k) {
            return;
        }

        // Isi unggahan dibaca lewat disk sementara Livewire (path asli belum tentu bisa dibaca langsung)
        $tmp = tempnam(sys_get_temp_dir(), 'absen');
        file_put_contents($tmp, (string) $this->foto->get());

        try {
            $a = $aksi($k, $tmp);
        } catch (BillingException $e) {
            $this->pin = '';
            $this->alert('Absen gagal', $e->getMessage(), 'error');

            return;
        } catch (\RuntimeException) {
            $this->addError('foto', 'File harus berupa foto.');

            return;
        } finally {
            @unlink($tmp);
        }

        $pesan = $jenis === 'masuk'
            ? "{$k->nama} masuk {$a->masuk_pada->format('H:i')}".($a->terlambat_menit > 0 ? " · terlambat {$a->terlambat_menit} menit" : '')
            : "{$k->nama} pulang {$a->pulang_pada->format('H:i')} · kerja ".Absensi::formatMenit($a->menit_kerja);

        $this->success($pesan);
        $this->reset(['karyawanId', 'pin', 'foto']);
        unset($this->daftar, $this->dipilih);
    }

    public function render(AbsensiService $absensi)
    {
        $k = $this->dipilih['k'] ?? null;

        return view('livewire.operator.absen', [
            'jadwal' => $k ? $absensi->jadwalHari($k, now()) : null,
        ]);
    }
}
