<?php

namespace App\Support;

use App\Models\Playbox;
use App\Models\SewaPlaybox;
use Illuminate\Support\Facades\File;

/**
 * Pemindai kunci terjemahan: semua __('…') / trans('…') / @lang('…') di kode + label dinamis (konstanta, menu).
 * Dipakai `php artisan bahasa:cek` & tes kelengkapan terjemahan.
 */
final class KunciTerjemahan
{
    /** Folder yang dipindai, dikelompokkan per area file terjemahan lang/app/{area}/{kode}.json */
    public const AREA = [
        'kasir' => ['resources/views/livewire', 'resources/views/layouts', 'resources/views/components', 'resources/views/partials', 'app/Livewire', 'resources/views/auth'],
        'admin' => ['app/Filament', 'resources/views/filament'],
        'umum' => ['app/Services', 'app/Models', 'app/Support', 'app/Http', 'app/Jobs', 'app/Notifications', 'app/Exceptions', 'app/Console',
            'resources/views/struk', 'resources/views/billboard', 'resources/views/booking', 'resources/views/laporan', 'resources/views/turnamen',
            'resources/views/pdf', 'routes', 'app/Providers'],
    ];

    /** @return array<string, list<string>> area => kunci unik */
    public static function perArea(): array
    {
        $hasil = [];

        foreach (self::AREA as $area => $folder) {
            $kunci = [];

            foreach ($folder as $f) {
                $path = base_path($f);

                if (! is_dir($path)) {
                    continue;
                }

                foreach (File::allFiles($path) as $file) {
                    if ($file->getExtension() === 'php') {
                        array_push($kunci, ...self::dariTeks($file->getContents()));
                    }
                }
            }

            $hasil[$area] = $kunci;
        }

        $hasil['kasir'] = array_merge($hasil['kasir'], self::dinamis());

        return array_map(fn ($k) => array_values(array_unique($k)), $hasil);
    }

    /** @return list<string> */
    public static function semua(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::perArea()))));
    }

    /** String PHP satu / dua kutip tanpa interpolasi */
    private const STR = '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\$]|\\\\.)*)")';

    /**
     * Pola pemanggilan yang argumennya kunci terjemahan:
     * __() / trans() / @lang(), notifikasi WithAlert (diterjemahkan di trait), pesan BillingException (diterjemahkan di konstruktor).
     */
    private static function pola(): array
    {
        $s = self::STR;

        return [
            '/(?:\b__|\btrans|@lang|\btrans_choice)\(\s*'.$s.'\s*[,)]/u',
            '/->(?:success|error|warning|info|toast|flashSuccess|flashToast|alert|flashAlert)\(\s*'.$s.'\s*[,)]/u',
            '/->(?:alert|flashAlert)\(\s*(?:'.$s.'|[^,()]+)\s*,\s*'.$s.'\s*[,)]/u',
            '/new\s+(?:BillingException|\\\\App\\\\Exceptions\\\\BillingException)\(\s*'.$s.'\s*[,)]/u',
            // addError('field', '…') & pesan validasi kustom 'field.rule' => '…' (diterjemahkan di WithAlert)
            '/->addError\(\s*[^,]+,\s*'.$s.'\s*\)/u',
            '/\'[\w.*]+\.(?:required|required_if|required_with|min|max|digits|digits_between|exists|in|not_in|integer|numeric|image|file|mimes|date|after|after_or_equal|before|size|regex|unique|gt|gte|lt|lte|email|between|confirmed|array|string|boolean|url|uuid)\'\s*=>\s*'.$s.'/u',
        ];
    }

    /** @return list<string> */
    public static function dariTeks(string $teks): array
    {
        $kunci = [];

        foreach (self::pola() as $pola) {
            if (! preg_match_all($pola, $teks, $m, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($m as $cocok) {
                // Ambil semua kelompok string yang terisi (pola alert punya dua argumen)
                for ($i = 1; $i < count($cocok); $i += 2) {
                    $tunggal = $cocok[$i] ?? '';
                    $ganda = $cocok[$i + 1] ?? '';

                    if ($tunggal === '' && $ganda === '') {
                        continue;
                    }

                    $k = $ganda !== '' ? stripcslashes($ganda) : str_replace(["\\'", '\\\\'], ["'", '\\'], $tunggal);

                    // Lewati kunci grup bawaan (validation.required, filament::…)
                    if (! str_contains($k, ' ') && (str_contains($k, '::') || preg_match('/^[a-z0-9_-]+\.[a-z0-9_.-]+$/', $k))) {
                        continue;
                    }

                    $kunci[] = $k;
                }
            }
        }

        return $kunci;
    }

    /** Label yang diterjemahkan lewat __($variabel): menu, konstanta model */
    public static function dinamis(): array
    {
        $menu = [];

        foreach (MenuOperator::daftar() as $grup => $item) {
            $menu[] = $grup;
            array_push($menu, ...array_column($item, 'label'));
        }

        $konstanta = [];

        foreach (self::KONSTANTA as [$kelas, $nama]) {
            // Nilai bisa bertingkat (mis. Turnamen::FORMAT = [kode => [nama, keterangan]])
            array_push($konstanta, ...array_filter(\Illuminate\Support\Arr::flatten(constant("{$kelas}::{$nama}")), 'is_string'));
        }

        return array_merge(
            $menu,
            $konstanta,
            self::EKSTRA,
            array_values(\App\Livewire\Concerns\PeriodeLaporan::daftarPeriode()),
            self::judulHalaman(),
        );
    }

    /** Nilai data yang ditampilkan lewat __($nilai) (bukan konstanta) */
    public const EKSTRA = ['kasir', 'online', 'Putih', 'Kuning', 'Hijau', 'Biru', 'Merah', 'Saldo', 'Poin', 'Stamp'];

    /** Konstanta [kode => label Indonesia] yang ditampilkan lewat __($label) */
    public const KONSTANTA = [
        [Playbox::class, 'SATUAN'], [Playbox::class, 'STATUS'],
        [SewaPlaybox::class, 'JAMINAN'], [SewaPlaybox::class, 'KONDISI'],
        [\App\Models\Penyewa::class, 'JENIS_TEMPAT'],
        [\App\Models\Aset::class, 'KATEGORI'], [\App\Models\Aset::class, 'STATUS'],
        [\App\Models\ModalMutasi::class, 'JENIS'], [\App\Models\ModalMutasi::class, 'SUMBER'],
        [\App\Livewire\Operator\AnalisaPintar::class, 'TAB'],
        [\App\Livewire\Operator\DaftarTransaksi::class, 'JENIS'], [\App\Livewire\Operator\DaftarTransaksi::class, 'STATUS'],
        [\App\Models\Maintenance::class, 'JENIS'], [\App\Models\Maintenance::class, 'STATUS'],
        [\App\Models\Pengeluaran::class, 'SUMBER_DANA'], [\App\Models\Pengeluaran::class, 'KATEGORI'], [\App\Models\Pengeluaran::class, 'KATEGORI_MANUAL'],
        [\App\Models\Turnamen::class, 'STATUS'], [\App\Models\Turnamen::class, 'FORMAT'],
        [\App\Livewire\Operator\Pembayaran::class, 'METODE'],
        [\App\Livewire\Operator\DetailTransaksi::class, 'METODE'], [\App\Livewire\Operator\DetailTransaksi::class, 'LABEL_LOG'],
        [\App\Models\Booking::class, 'STATUS'], [\App\Models\LogTv::class, 'LABEL'],
        [\App\Livewire\Operator\Stok::class, 'TAB'], [\App\Models\StokMutasi::class, 'JENIS'],
        [\App\Livewire\Operator\MulaiSesi::class, 'MODE'], [\App\Models\PembayaranOnline::class, 'STATUS'],
        [\App\Livewire\Operator\Member::class, 'AKUN'], [\App\Models\MemberMutasi::class, 'LABEL_JENIS'],
        [\App\Livewire\Operator\KelolaSesi::class, 'ALASAN_BONUS'], [\App\Livewire\Operator\KelolaSesi::class, 'ALASAN_PINDAH'],
        [\App\Livewire\Operator\KelolaSesi::class, 'ALASAN_BATAL_SESI'], [\App\Services\Tv\TvRemoteService::class, 'PERINTAH'],
        [PemberitahuanTv::class, 'PESAN_BAWAAN'], [PemberitahuanTv::class, 'DURASI'], [PemberitahuanTv::class, 'UKURAN'], [PemberitahuanTv::class, 'HURUF'],
        [RunningTextTv::class, 'DURASI'], [RunningTextTv::class, 'POSISI'], [RunningTextTv::class, 'UKURAN'], [RunningTextTv::class, 'KECEPATAN'],
    ];

    /** #[Title('…')] komponen Livewire (diterjemahkan di layout) */
    private static function judulHalaman(): array
    {
        $judul = [];

        foreach (File::allFiles(app_path('Livewire')) as $file) {
            if (preg_match_all("/#\[Title\('([^']+)'\)\]/", $file->getContents(), $m)) {
                array_push($judul, ...$m[1]);
            }
        }

        return $judul;
    }

    /** @return array<string, string> terjemahan gabungan semua area untuk satu bahasa */
    public static function terjemahan(string $kode): array
    {
        $isi = [];

        foreach (glob(lang_path("app/*/{$kode}.json")) ?: [] as $file) {
            $isi += (array) json_decode((string) file_get_contents($file), true);
        }

        return $isi;
    }
}
