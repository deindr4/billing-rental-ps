<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\WithAlert;
use App\Models\Maintenance;
use App\Models\Pengaturan;
use App\Models\PerangkatTv;
use App\Models\Sesi;
use App\Models\SesiAksesori;
use App\Models\TipeKonsol;
use App\Models\Transaksi;
use App\Models\Unit;
use App\Services\Aset\MaintenanceService;
use App\Services\Billing\BillingService;
use App\Services\Tv\TvRemoteService;
use App\Support\RunningTextTv;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.operator')]
#[Title('Rental')]
class Rental extends Component
{
    use WithAlert, WithPagination;

    #[Url(as: 'status', except: 'semua')]
    public string $filterStatus = 'semua';

    #[Url(as: 'cari', except: '')]
    public string $cari = '';

    /** ps | pc — menu "Rental PS" & "Rental PC" (RentalPc) memakai halaman ini */
    public string $jenis = TipeKonsol::JENIS_PS;

    /** kotak | daftar — diingat per login; daftar dipecah per halaman agar tidak memanjang */
    #[Session(key: 'rental-tampilan')]
    public string $tampilan = 'kotak';

    public const PER_HALAMAN = 15;

    /** Filter / cari / tampilan berubah → kembali ke halaman 1 */
    public function updated(string $properti): void
    {
        if (in_array($properti, ['filterStatus', 'cari', 'tampilan'], true)) {
            $this->resetPage();
        }
    }

    /** Dipanggil saat sesi berubah (mulai, tambah waktu, selesai, bayar, dll.) */
    #[On('sesi-berubah')]
    #[On('running-text-berubah')]
    public function segarkan(): void
    {
        // Cukup memicu render ulang
    }

    /**
     * Remote TV dari kartu unit. Volume cukup izin rental; daya & restart butuh izin tv.remote.
     * Dipanggil langsung atau dari tombol konfirmasi (parameter terakhir berisi hasil konfirmasi).
     */
    public function perintahTv(string $unitId, string $perintah, array $konfirmasi = []): void
    {
        $user = auth()->user();

        // Tutup aplikasi (butuh PIN, panel TV) & update APK (admin) tidak boleh lewat remote kartu unit
        if (in_array($perintah, TvRemoteService::PERINTAH_KHUSUS, true)) {
            $this->alert('Akses ditolak', 'Perintah ini hanya dari panel TV (dengan PIN) atau admin.', 'error');

            return;
        }

        // Volume & tutup game (game hang di PC) cukup izin rental; daya, restart, log off butuh izin remote
        $izin = str_starts_with($perintah, 'volume_') || $perintah === 'tutup_game' ? 'rental.kelola' : 'tv.remote';

        if (! $user->can($izin)) {
            $this->alert('Akses ditolak', 'Perintah ini butuh izin Remote TV / PC.', 'error');

            return;
        }

        $perangkat = PerangkatTv::aktif()->where('unit_id', $unitId)->first();

        if (! $perangkat) {
            $this->error('TV / PC tidak terhubung');

            return;
        }

        try {
            app(TvRemoteService::class)->kirim($perangkat, $perintah, $user);
        } catch (BillingException $e) {
            $this->error($e->getMessage());

            return;
        }

        if (! str_starts_with($perintah, 'volume_')) {
            $this->success(__(':perintah dikirim', ['perintah' => __(TvRemoteService::PERINTAH[$perintah])]));
        }
    }

    /** PC: Task Manager boleh dibuka sementara (game hang/crash), lalu diblok lagi otomatis */
    public function izinTaskManager(string $unitId, array $konfirmasi = []): void
    {
        $this->aksiPc($unitId, function (PerangkatTv $pc, TvRemoteService $remote) {
            $menit = $remote->izinTaskManager($pc, auth()->user());
            $this->success(__('Task Manager di :unit terbuka :menit menit', ['unit' => $pc->unit?->nama, 'menit' => $menit]));
        });
    }

    /** PC mati: nyalakan lewat Wake-on-LAN (dari server lokal, atau dititipkan ke PC lain yang menyala) */
    public function nyalakanPc(string $unitId): void
    {
        $this->aksiPc($unitId, function (PerangkatTv $pc, TvRemoteService $remote) {
            $lewat = $remote->bangunkanPc($pc, auth()->user());
            $this->success($lewat === 'server'
                ? __('Perintah nyala dikirim. Tunggu ±1 menit.')
                : __('Perintah nyala dikirim lewat :pc. Tunggu ±1 menit.', ['pc' => $lewat]));
        });
    }

    private function aksiPc(string $unitId, callable $aksi): void
    {
        if (! auth()->user()->can('tv.remote')) {
            $this->alert('Akses ditolak', 'Perintah ini butuh izin Remote TV / PC.', 'error');

            return;
        }

        $pc = PerangkatTv::aktif()->with('unit:id,nama')->where('unit_id', $unitId)->where('jenis', PerangkatTv::JENIS_PC)->first();

        if (! $pc) {
            $this->error('PC belum dipasangkan');

            return;
        }

        try {
            $aksi($pc, app(TvRemoteService::class));
        } catch (BillingException $e) {
            $this->error($e->getMessage());
        }
    }

    /** Akhiri waktu pilih game lebih cepat: waktu sewa mulai berjalan sekarang */
    public function mulaiSekarang(string $sesiId, BillingService $billing): void
    {
        if (! auth()->user()->can('rental.kelola')) {
            $this->alert('Akses ditolak', 'Anda tidak punya izin untuk aksi ini.', 'error');

            return;
        }

        $sesi = Sesi::find($sesiId);

        if (! $sesi) {
            return;
        }

        try {
            $billing->mulaiSekarang($sesi, auth()->user());
        } catch (BillingException $e) {
            $this->error($e->getMessage());

            return;
        }

        $this->success('Waktu sewa mulai berjalan');
        $this->dispatch('sesi-berubah');
    }

    /** Unit selesai servis -> kembali siap dipakai (dipanggil dari tombol konfirmasi) */
    public function tandaiSiap(string $unitId, array $konfirmasi = []): void
    {
        if (! auth()->user()->can('rental.kelola')) {
            $this->alert('Akses ditolak', 'Anda tidak punya izin untuk aksi ini.', 'error');

            return;
        }

        $unit = Unit::find($unitId);

        if (! $unit || $unit->status !== Unit::STATUS_SERVIS) {
            $this->error('Unit tidak sedang dalam status servis');

            return;
        }

        // Tiket maintenance yang masih dikerjakan ikut ditutup
        $tiket = Maintenance::query()->where('unit_id', $unit->id)->where('status', 'dikerjakan')->get();

        foreach ($tiket as $m) {
            app(MaintenanceService::class)->selesai($m, auth()->user(), ['hasil' => 'Ditandai siap dari halaman Rental']);
        }

        $unit->refresh();

        if ($unit->status === Unit::STATUS_SERVIS) {
            $unit->update(['status' => Unit::STATUS_KOSONG]);
        }

        $this->success(__(':unit siap dipakai', ['unit' => $unit->nama]));
        $this->dispatch('sesi-berubah');
    }

    public function render(BillingService $billing)
    {
        $semuaUnit = Unit::aktif()
            ->jenis($this->jenis)
            ->with(['tipeKonsol:id,kode,nama,jenis', 'kategori:id,nama'])
            ->urut()
            ->get();

        $sesiPerUnit = $this->sesiPerUnit($semuaUnit);

        // Aksesori yang sedang disewa per sesi (chip di kartu unit)
        $aksesoriPerSesi = SesiAksesori::query()->with('aksesori:id,nama')
            ->whereIn('sesi_id', $sesiPerUnit->pluck('id'))->dipakai()->where('dibatalkan', false)
            ->get()->groupBy('sesi_id');

        // TV Agent yang terpasang per unit (untuk indikator online/offline)
        $tvPerUnit = PerangkatTv::aktif()
            ->whereIn('unit_id', $semuaUnit->pluck('id'))
            ->get(['id', 'unit_id', 'jenis', 'mac', 'terakhir_online', 'bypass_sampai', 'status', 'volume', 'senyap', 'layar_hidup', 'diagnostik', 'input_hdmi', 'hdmi_nama'])
            ->keyBy('unit_id');

        $tarif = $semuaUnit->mapWithKeys(fn (Unit $unit) => [
            $unit->id => rescue(fn () => $billing->tarifPerJam($unit), null, false),
        ]);

        $ringkasan = [
            'semua' => $semuaUnit->count(),
            'kosong' => $semuaUnit->where('status', Unit::STATUS_KOSONG)->count(),
            'main' => $semuaUnit->whereIn('status', [Unit::STATUS_MAIN, Unit::STATUS_PAUSE])->count(),
            'menunggu_bayar' => $semuaUnit->where('status', Unit::STATUS_MENUNGGU_BAYAR)->count(),
            'servis' => $semuaUnit->where('status', Unit::STATUS_SERVIS)->count(),
        ];

        $units = $semuaUnit
            ->when($this->filterStatus === 'main', fn ($c) => $c->whereIn('status', [Unit::STATUS_MAIN, Unit::STATUS_PAUSE]))
            ->when(! in_array($this->filterStatus, ['semua', 'main'], true), fn ($c) => $c->where('status', $this->filterStatus))
            ->when($this->cari !== '', function ($c) {
                $kata = mb_strtolower($this->cari);

                return $c->filter(fn (Unit $u) => str_contains(mb_strtolower($u->nama.' '.$u->kode), $kata));
            })
            ->values();

        // Daftar: per halaman (kotak tetap semua unit, seperti matriks di layar kasir)
        if ($this->tampilan === 'daftar') {
            $halaman = min($this->getPage(), max(1, (int) ceil($units->count() / self::PER_HALAMAN)));
            $units = new LengthAwarePaginator($units->forPage($halaman, self::PER_HALAMAN)->values(), $units->count(), self::PER_HALAMAN, $halaman);
        }

        return view('livewire.operator.rental', [
            'jenis' => $this->jenis,
            'units' => $units,
            'sesiPerUnit' => $sesiPerUnit,
            'aksesoriPerSesi' => $aksesoriPerSesi,
            'tvPerUnit' => $tvPerUnit,
            'bisaRemote' => (bool) auth()->user()?->can('tv.remote'),
            'tarif' => $tarif,
            'ringkasan' => $ringkasan,
            'peringatanMenit' => (int) Pengaturan::ambil('tv.peringatan_menit', 5),
            'runningText' => RunningTextTv::tayang(RunningTextTv::ambil(app(Tenancy::class)->cabangId())),
            'serverNow' => now()->getTimestampMs(),
        ]);
    }

    /** Sesi yang sedang berjalan/dijeda, atau selesai tapi belum dibayar, per unit. */
    private function sesiPerUnit(Collection $units): Collection
    {
        return Sesi::query()
            ->with(['transaksi:id,nomor,total,status,pelanggan_nama', 'paketHarga:id,nama'])
            ->whereIn('unit_id', $units->pluck('id'))
            ->where(function ($q) {
                $q->aktif()->orWhere(function ($q) {
                    $q->where('status', Sesi::STATUS_SELESAI)
                        ->whereHas('transaksi', fn ($t) => $t->where('status', Transaksi::STATUS_BELUM_BAYAR));
                });
            })
            ->orderBy('mulai_pada')
            ->get()
            ->keyBy('unit_id');
    }
}
