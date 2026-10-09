<?php

namespace App\Filament\Pages;

use App\Models\JadwalKaryawan;
use App\Models\Karyawan;
use App\Models\TemplateShift;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** Jadwal mingguan berulang: tabel karyawan × hari, tiap sel pilih jam shift atau libur */
class JadwalKaryawanPage extends Page
{
    protected string $view = 'filament.pages.jadwal-karyawan';

    protected static ?string $slug = 'jadwal-karyawan';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Karyawan';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Jadwal mingguan';

    protected static ?string $title = 'Jadwal mingguan karyawan';

    /** [karyawan_id => [hari => template_shift_id | '']] */
    public array $jadwal = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('karyawan.kelola');
    }

    public function mount(): void
    {
        $ada = JadwalKaryawan::query()->get()->groupBy('karyawan_id');

        foreach ($this->karyawan() as $k) {
            foreach (array_keys(JadwalKaryawan::HARI) as $hari) {
                $this->jadwal[$k->id][$hari] = (string) ($ada->get($k->id)?->firstWhere('hari', $hari)?->template_shift_id ?? '');
            }
        }
    }

    public function karyawan()
    {
        return Karyawan::aktif()->with('cabang:id,kode')->orderBy('nama')->get();
    }

    public function template()
    {
        return TemplateShift::aktif()->orderBy('urutan')->get();
    }

    /** Salin jadwal Senin ke semua hari untuk satu karyawan */
    public function samakan(string $karyawanId): void
    {
        $senin = $this->jadwal[$karyawanId][1] ?? '';

        foreach (array_keys(JadwalKaryawan::HARI) as $hari) {
            $this->jadwal[$karyawanId][$hari] = $senin;
        }
    }

    public function simpan(): void
    {
        $tenantId = app(Tenancy::class)->tenantId();
        $templateSah = $this->template()->pluck('id')->all();
        $karyawanSah = $this->karyawan()->pluck('id')->all();

        DB::transaction(function () use ($tenantId, $templateSah, $karyawanSah) {
            foreach ($this->jadwal as $karyawanId => $hariList) {
                if (! in_array($karyawanId, $karyawanSah, true)) {
                    continue;
                }

                foreach ($hariList as $hari => $templateId) {
                    $hari = (int) $hari;
                    $baris = JadwalKaryawan::query()->where('karyawan_id', $karyawanId)->where('hari', $hari)->first();

                    if ($templateId === '' || ! in_array($templateId, $templateSah, true)) {
                        $baris?->delete(); // libur

                        continue;
                    }

                    if ($baris) {
                        $baris->update(['template_shift_id' => $templateId]);
                    } else {
                        JadwalKaryawan::create(['tenant_id' => $tenantId, 'karyawan_id' => $karyawanId, 'hari' => $hari, 'template_shift_id' => $templateId]);
                    }
                }
            }
        });

        Notification::make()->title('Jadwal disimpan')->success()->send();
    }
}
