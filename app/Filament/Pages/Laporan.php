<?php

namespace App\Filament\Pages;

use App\Livewire\Concerns\PeriodeLaporan;
use App\Models\Cabang;
use App\Services\LaporanService;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Laporan owner: per cabang atau gabungan semua cabang.
 */
class Laporan extends Page
{
    use PeriodeLaporan;

    protected string $view = 'filament.pages.laporan';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $title = 'Laporan';

    /** '' = semua cabang */
    public string $cabang = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('laporan.lihat');
    }

    public function mount(): void
    {
        $this->siapkanPeriode();
        $this->cabang = $this->bolehSemua() ? '' : (string) $this->daftarCabang()->keys()->first();
    }

    private function bolehSemua(): bool
    {
        $user = auth()->user();

        return $user->isSuperAdmin() || $user->hasRole('Owner');
    }

    /** Cabang yang boleh dilihat user */
    private function daftarCabang(): Collection
    {
        $user = auth()->user();

        $query = $user->tenant_id
            ? $user->cabangTersedia()
            : Cabang::query()->orderBy('kode');

        return $query->get(['id', 'kode', 'nama'])->keyBy('id');
    }

    protected function getViewData(): array
    {
        $tenancy = app(Tenancy::class);
        $cabangAsal = $tenancy->cabangId();
        $daftar = $this->daftarCabang();
        $laporan = app(LaporanService::class);

        if ($this->cabang !== '' && ! $daftar->has($this->cabang)) {
            $this->cabang = '';
        }

        if ($this->cabang === '' && ! $this->bolehSemua()) {
            $this->cabang = (string) $daftar->keys()->first();
        }

        try {
            // Scope cabang mengikuti pilihan; kosong = semua cabang di tenant
            $tenancy->setCabang($this->cabang !== '' ? $this->cabang : null);
            $data = $this->dataLaporan($laporan);

            $perCabang = [];

            if ($this->cabang === '' && $daftar->count() > 1) {
                [$dari, $sampai] = $this->rentang();

                foreach ($daftar as $c) {
                    $tenancy->setCabang($c->id);
                    $perCabang[] = [
                        'kode' => $c->kode,
                        'nama' => $c->nama,
                        'r' => $laporan->ringkasan($dari, $sampai),
                    ];
                }
            }
        } finally {
            $tenancy->setCabang($cabangAsal);
        }

        return $data + [
            'perCabang' => $perCabang,
            'daftarCabang' => $daftar,
            'bolehSemua' => $this->bolehSemua(),
        ];
    }
}
