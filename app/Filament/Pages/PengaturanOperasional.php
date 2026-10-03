<?php

namespace App\Filament\Pages;

use App\Models\Cabang;
use App\Models\Pengaturan;
use App\Services\Billing\PengeluaranService;
use App\Services\Publik\BookingService;
use App\Services\Publik\QrisService;
use App\Services\Struk\StrukService;
use App\Services\Tv\NotifikasiTv;
use App\Services\Tv\StatusTvService;
use App\Support\PemberitahuanTv;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use UnitEnum;

/**
 * Pengaturan operasional per cabang: batas pengeluaran, open billing, pause, peringatan.
 */
class PengaturanOperasional extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.pengaturan-operasional';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Operasional';

    protected static ?string $title = 'Pengaturan Operasional';

    /** kunci pengaturan => [field, default] */
    private const KUNCI = [
        'pengeluaran.plafon_shift' => ['batas_pengeluaran', PengeluaranService::PLAFON_DEFAULT],
        'open_billing.blok_menit' => ['blok_menit', 15],
        'open_billing.toleransi_menit' => ['toleransi_menit', 5],
        'open_billing.minimal_menit' => ['minimal_menit', 60],
        'open_billing.pembulatan_rupiah' => ['pembulatan_rupiah', 0],
        'pause.maksimal_kali' => ['maks_pause', 2],
        'tv.peringatan_menit' => ['peringatan_menit', 5],
        'tv.opasitas_timer' => ['opasitas_timer', 90],
        'sesi.pilih_game_menit' => ['pilih_game_menit', 5],
        'sesi.batal_tanpa_pin_menit' => ['batal_tanpa_pin', 5],
        'tv.durasi_bypass_menit' => ['durasi_bypass', 15],
        'tv.bypass_maks_menit' => ['bypass_maks', 120],
    ];

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('admin.pengaturan');
    }

    public function mount(): void
    {
        $this->muatCabang(app(Tenancy::class)->cabangId() ?? $this->daftarCabang()->keys()->first());
    }

    public function muatCabang(?string $cabangId): void
    {
        $isi = ['cabang_id' => $cabangId];

        foreach (self::KUNCI as $kunci => [$field, $default]) {
            $isi[$field] = $cabangId ? (int) Pengaturan::ambil($kunci, $default, $cabangId) : $default;
        }

        $struk = $cabangId ? app(StrukService::class)->setelan($cabangId) : ['lebar' => 58, 'header' => '', 'footer' => StrukService::FOOTER_DEFAULT];
        $isi['struk_lebar'] = (string) $struk['lebar'];
        $isi['struk_header'] = $struk['header'];
        $isi['struk_footer'] = $struk['footer'];
        $isi['tv_suara_aktif'] = $cabangId ? (bool) Pengaturan::ambil('tv.suara_aktif', true, $cabangId) : true;
        $isi['tv_ukuran_timer'] = $cabangId ? (string) Pengaturan::ambil('tv.ukuran_timer', 'sedang', $cabangId) : 'sedang';
        $isi['tv_warna_timer'] = $cabangId ? (string) Pengaturan::ambil('tv.warna_timer', 'putih', $cabangId) : 'putih';
        $isi['tv_info_teknis'] = $cabangId ? (bool) Pengaturan::ambil('tv.info_teknis', true, $cabangId) : true;
        $isi['tv_kunci_remote'] = $cabangId ? (bool) Pengaturan::ambil('tv.kunci_remote', true, $cabangId) : true;
        $isi['tv_pengumuman'] = $cabangId ? (string) Pengaturan::ambil('tv.pengumuman', '', $cabangId) : '';
        $isi['tv_pesan_cepat'] = implode("\n", PemberitahuanTv::pesanCepat($cabangId));
        $isi['tv_aplikasi'] = StatusTvService::aplikasiDiizinkan($cabangId);
        $isi['server_lokal'] = $cabangId ? (string) Pengaturan::ambil('server.url_lokal', '', $cabangId) : '';
        $isi['server_cloud'] = $cabangId ? (string) Pengaturan::ambil('server.url_cloud', '', $cabangId) : '';
        $isi['pilih_game_otomatis'] = $cabangId ? (bool) Pengaturan::ambil('sesi.pilih_game_otomatis', true, $cabangId) : true;

        $isi['qris_aktif'] = $cabangId ? (bool) Pengaturan::ambil('qris.aktif', false, $cabangId) : false;
        $isi['qris_payload'] = $cabangId ? (string) Pengaturan::ambil('qris.payload', '', $cabangId) : '';

        $jam = $cabangId ? BookingService::jamOperasionalCabang($cabangId) : ['buka' => '10:00', 'tutup' => '23:00', 'keterangan' => 'Setiap hari'];
        $isi['bm_aktif'] = $cabangId ? (bool) Pengaturan::ambil('bayar_mandiri.aktif', false, $cabangId) : false;
        $isi['bm_minimal_menit'] = $cabangId ? (int) Pengaturan::ambil('bayar_mandiri.minimal_menit', 30, $cabangId) : 30;
        $isi['bm_masa_qris_menit'] = $cabangId ? (int) Pengaturan::ambil('bayar_mandiri.masa_qris_menit', 10, $cabangId) : 10;
        $isi['bm_selesai_otomatis_menit'] = $cabangId ? (int) Pengaturan::ambil('bayar_mandiri.selesai_otomatis_menit', 10, $cabangId) : 10;
        $isi['jam_buka'] = $jam['buka'];
        $isi['jam_tutup'] = $jam['tutup'];
        $isi['jam_keterangan'] = $jam['keterangan'];

        if ($cabangId) {
            $bk = app(BookingService::class)->aturan($cabangId);

            foreach ($bk as $k => $v) {
                $isi['booking_'.$k] = $k === 'durasi' ? implode(',', $v) : $v;
            }
        }

        $this->form->fill($isi);
    }

    private function daftarCabang()
    {
        return Cabang::query()
            ->where('tenant_id', app(Tenancy::class)->tenantId())
            ->orderBy('kode')
            ->pluck('nama', 'id');
    }

    private static function uang(TextInput $input): TextInput
    {
        return $input
            ->prefix('Rp')
            ->mask(RawJs::make("\$money(\$input, ',', '.', 0)"))
            ->stripCharacters('.')
            ->numeric()
            ->minValue(0);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('cabang_id')
                            ->label('Cabang')
                            ->options(fn () => $this->daftarCabang())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn ($state) => $this->muatCabang($state))
                            ->helperText('Pengaturan di bawah berlaku untuk cabang yang dipilih.'),
                    ]),

                Section::make('Kas & pengeluaran')
                    ->schema([
                        self::uang(TextInput::make('batas_pengeluaran'))
                            ->label('Batas pengeluaran kas laci per shift')
                            ->helperText('Pengeluaran dari laci melebihi batas ini butuh PIN supervisor/owner. Isi 0 untuk tanpa batas.')
                            ->required(),
                    ]),

                Section::make('Open billing')
                    ->columns(2)
                    ->schema([
                        TextInput::make('blok_menit')
                            ->label('Dihitung per (menit)')
                            ->helperText('Contoh 15: main 61 menit ditagih 75 menit.')
                            ->numeric()->minValue(1)->maxValue(60)->required(),
                        TextInput::make('minimal_menit')
                            ->label('Minimal ditagih (menit)')
                            ->numeric()->minValue(0)->maxValue(240)->required(),
                        TextInput::make('toleransi_menit')
                            ->label('Toleransi gratis (menit)')
                            ->helperText('Main sampai sekian menit lalu berhenti, tidak ditagih.')
                            ->numeric()->minValue(0)->maxValue(30)->required(),
                        self::uang(TextInput::make('pembulatan_rupiah'))
                            ->label('Pembulatan tagihan')
                            ->helperText('Contoh 500: Rp 8.750 jadi Rp 9.000. Isi 0 tanpa pembulatan.')
                            ->maxValue(10000)
                            ->required(),
                    ]),

                Section::make('Sesi & TV')
                    ->columns(2)
                    ->schema([
                        TextInput::make('maks_pause')
                            ->label('Maksimal pause per sesi')
                            ->helperText('Isi 0 untuk tanpa batas.')
                            ->numeric()->minValue(0)->maxValue(10)->required(),
                        TextInput::make('peringatan_menit')
                            ->label('Peringatan sisa waktu (menit)')
                            ->helperText('Di bawah batas ini timer di TV berubah merah; tepat di batas & sisa 1 menit timer berkedip + bunyi. Kartu kasir berwarna kuning.')
                            ->numeric()->minValue(1)->maxValue(30)->required(),
                        TextInput::make('opasitas_timer')
                            ->label('Kepekatan timer di TV (%)')
                            ->helperText('Timer melayang di atas game. 100 = pekat, 40 = tembus pandang (game di belakang terlihat). '
                                .'Berlaku juga saat waktu hampir habis (angka merah). APK TV ≥ 0.6.3.')
                            ->numeric()->minValue(30)->maxValue(100)->suffix('%')->required(),
                        Select::make('tv_ukuran_timer')
                            ->label('Ukuran timer di TV')
                            ->options(['kecil' => 'Kecil', 'sedang' => 'Sedang', 'besar' => 'Besar'])
                            ->helperText('Ukuran timer melayang di pojok layar saat main. APK TV ≥ 0.6.3.')
                            ->selectablePlaceholder(false)
                            ->required(),
                        Select::make('tv_warna_timer')
                            ->label('Warna angka timer di TV')
                            ->options(['putih' => 'Putih', 'hijau' => 'Hijau', 'biru' => 'Biru', 'tosca' => 'Tosca', 'kuning' => 'Kuning'])
                            ->helperText('Warna saat waktu masih banyak. Di bawah batas "Peringatan sisa waktu" angka selalu berubah merah. APK TV ≥ 0.6.4.')
                            ->selectablePlaceholder(false)
                            ->required(),
                        Toggle::make('tv_info_teknis')
                            ->label('Info teknis di bawah timer TV')
                            ->helperText('Baris kecil: versi APK + respon ke server lokal (L) & cloud (C) dalam ms. Hijau lancar, kuning lambat, '
                                .'merah tidak terjangkau. Matikan jika tidak ingin terlihat pelanggan. APK TV ≥ 0.6.5.')
                            ->inline(false),
                        Toggle::make('tv_kunci_remote')
                            ->label('Kunci remote saat main')
                            ->helperText('Saat PS tampil hanya Volume, Home & OK yang diteruskan; panah, Back, angka, dll. ditahan '
                                .'(remote TV sebelah tidak ikut menggerakkan menu PS). Butuh "Izin kunci remote" di menu staf tiap TV. '
                                .'Tombol yang ditahan tercatat di Diagnostik. APK TV ≥ 0.6.6.')
                            ->inline(false),
                        TextInput::make('pilih_game_menit')
                            ->label('Waktu pilih game (menit)')
                            ->helperText('TV terbuka lebih dulu, waktu sewa mulai setelahnya & tidak ditagih. Isi 0 untuk mematikan.')
                            ->numeric()->minValue(0)->maxValue(15)->required(),
                        Toggle::make('pilih_game_otomatis')
                            ->label('Tercentang otomatis saat mulai sesi')
                            ->helperText('Kasir tetap bisa mematikannya per sesi.')
                            ->inline(false),
                        TextInput::make('batal_tanpa_pin')
                            ->label('Batal tanpa PIN (menit)')
                            ->helperText('Kasir boleh membatalkan tambah waktu / sesi yang ia buat sendiri tanpa PIN selama sekian menit '
                                .'(salah pencet, tidak jadi main). Sesudahnya butuh PIN supervisor/owner. Isi 0 = selalu PIN.')
                            ->numeric()->minValue(0)->maxValue(30)->suffix('menit')->required(),
                        TextInput::make('durasi_bypass')
                            ->label('Durasi bypass default (menit)')
                            ->helperText('Bisa diganti per unit di Admin → Unit.')
                            ->numeric()->minValue(5)->maxValue(1440)->required(),
                        TextInput::make('bypass_maks')
                            ->label('Batas maksimal bypass (menit)')
                            ->helperText('Pilihan durasi & perpanjangan tidak bisa melebihi ini.')
                            ->numeric()->minValue(5)->maxValue(1440)->required(),
                        Repeater::make('tv_aplikasi')
                            ->label('Aplikasi yang boleh dibuka saat bypass')
                            ->helperText('Selain PS (HDMI). Nama paket bisa dilihat di Admin → Perangkat TV → Diagnostik, atau di Play Store (id=…).')
                            ->schema([
                                TextInput::make('nama')->label('Nama')->required()->maxLength(40)->placeholder('YouTube'),
                                TextInput::make('paket')->label('Nama paket Android')->required()->maxLength(120)
                                    ->regex('/^[a-zA-Z][\w.]+$/')->placeholder('com.google.android.youtube.tv'),
                            ])
                            ->columns(2)
                            ->maxItems(8)
                            ->defaultItems(0)
                            ->addActionLabel('Tambah aplikasi')
                            ->columnSpanFull(),
                        Toggle::make('tv_suara_aktif')
                            ->label('Bunyi peringatan di TV')
                            ->helperText('Bunyi saat sisa waktu mencapai batas peringatan, sisa 1 menit, dan waktu habis.')
                            ->columnSpanFull(),
                        Textarea::make('tv_pengumuman')
                            ->label('Pengumuman berjalan di layar TV')
                            ->placeholder('Turnamen FC 25 Sabtu ini! Daftar di kasir…')
                            ->helperText('Tampil sebagai teks berjalan di bawah layar kunci & tagihan TV. Kosongkan jika tidak ada. '
                                .'Untuk promo yang juga berjalan di atas game, pakai tombol "Running text" di halaman Rental kasir.')
                            ->rows(2)
                            ->maxLength(300)
                            ->columnSpanFull(),
                        Textarea::make('tv_pesan_cepat')
                            ->label('Pesan cepat pemberitahuan TV')
                            ->helperText('Satu pesan per baris (maks. 150 karakter, emoji boleh). Muncul sebagai pilihan di tombol Pemberitahuan kasir. Kosongkan untuk pesan bawaan.')
                            ->rows(5)
                            ->columnSpanFull(),
                    ]),

                Section::make('Server lokal & cloud')
                    ->description('TV Agent memakai server lokal (LAN rental). Jika server lokal mati, TV otomatis pindah ke server cloud dan kembali ke lokal saat hidup lagi.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('server_lokal')
                            ->label('Alamat server lokal')
                            ->placeholder('http://192.168.1.10')
                            ->helperText('IP komputer server di jaringan rental.')
                            ->maxLength(200),
                        TextInput::make('server_cloud')
                            ->label('Alamat server cloud')
                            ->placeholder('https://billing.domainanda.com')
                            ->helperText('Cadangan saat server lokal mati. Kosongkan jika belum ada.')
                            ->maxLength(200),
                    ]),

                Section::make('QRIS')
                    ->description('Pelanggan scan QR di layar kasir, nominal tagihan otomatis terisi. Dana masuk ke rekening QRIS rental Anda; kasir konfirmasi setelah notifikasi masuk.')
                    ->schema([
                        Toggle::make('qris_aktif')->label('Tampilkan QR saat pembayaran QRIS'),
                        Textarea::make('qris_payload')
                            ->label('Isi QRIS statis rental')
                            ->rows(3)
                            ->maxLength(1000)
                            ->live(onBlur: true)
                            ->rule(fn () => function (string $attribute, $value, \Closure $gagal) {
                                if (trim((string) $value) !== '' && ! app(QrisService::class)->valid(trim((string) $value))) {
                                    $gagal('Isi QRIS tidak valid (CRC tidak cocok). Salin ulang teks dari hasil scan stiker QRIS.');
                                }
                            })
                            ->helperText(function ($get) {
                                $p = trim((string) $get('qris_payload'));

                                if ($p !== '' && app(QrisService::class)->valid($p)) {
                                    $m = app(QrisService::class)->merchant($p);

                                    return "✓ QRIS valid: {$m['nama']} · {$m['kota']}";
                                }

                                return 'Scan stiker QRIS rental dengan aplikasi pemindai QR (misal Google Lens) lalu salin teks hasil scan (diawali 000201...) ke sini.';
                            }),
                    ]),

                Section::make('Bayar mandiri di TV (QRIS)')
                    ->description('Pelanggan scan QR di TV, bayar nominal bebas → TV terbuka otomatis (durasi = nominal ÷ tarif per jam). Hanya saat kas dibuka. Gateway diatur di Pengaturan → Pembayaran online.')
                    ->columns(3)
                    ->schema([
                        Toggle::make('bm_aktif')->label('Aktifkan bayar mandiri di TV cabang ini')->columnSpanFull(),
                        TextInput::make('bm_minimal_menit')->label('Minimal beli (menit)')->numeric()->minValue(15)->maxValue(240)->required(),
                        TextInput::make('bm_masa_qris_menit')->label('QRIS berlaku (menit)')->numeric()->minValue(3)->maxValue(60)->required(),
                        TextInput::make('bm_selesai_otomatis_menit')->label('Selesai otomatis setelah habis (menit)')->numeric()->minValue(1)->maxValue(120)->required()
                            ->helperText('Sesi bayar mandiri yang lunas & tidak diisi ulang diselesaikan, unit kosong lagi.'),
                    ]),

                Section::make('Jam operasional')
                    ->description('Tampil di billboard & dipakai sebagai jam booking online. Status BUKA/TUTUP di billboard mengikuti kas: buka saat ada kasir yang membuka kas.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('jam_buka')->label('Jam buka')->placeholder('10:00')->regex('/^\d{2}:\d{2}$/')->required(),
                        TextInput::make('jam_tutup')->label('Jam tutup')->placeholder('23:00')->regex('/^\d{2}:\d{2}$/')->required()
                            ->helperText('Boleh lewat tengah malam, misal 02:00.'),
                        TextInput::make('jam_keterangan')->label('Keterangan')->placeholder('Setiap hari')->maxLength(60),
                    ]),

                Section::make('Booking online')
                    ->description(fn ($get) => 'Pelanggan memesan dari HP. Alamat portal: '.(($c = Cabang::find($get('cabang_id'))) ? route('booking', $c->kode) : '-'))
                    ->columns(3)
                    ->schema([
                        Toggle::make('booking_aktif')->label('Buka booking online')->columnSpanFull(),
                        TextInput::make('booking_durasi')->label('Pilihan durasi (menit)')->placeholder('60,120,180')->required()
                            ->regex('/^\d+(\s*,\s*\d+)*$/'),
                        TextInput::make('booking_min_menit_sebelum')->label('Paling cepat (menit sebelum main)')->numeric()->minValue(0)->required(),
                        TextInput::make('booking_maks_hari')->label('Paling jauh (hari ke depan)')->numeric()->minValue(1)->maxValue(30)->required(),
                        TextInput::make('booking_toleransi_menit')->label('Toleransi terlambat (menit)')->numeric()->minValue(0)->required()
                            ->helperText('Lewat dari ini tanpa datang → "tidak datang", unit dilepas.'),
                        TextInput::make('booking_jeda_menit')->label('Jeda antar booking (menit)')->numeric()->minValue(0)->required(),
                        TextInput::make('booking_maks_aktif_per_hp')->label('Maks. booking aktif per nomor')->numeric()->minValue(1)->required(),
                        Toggle::make('booking_konfirmasi_otomatis')->label('Langsung terkonfirmasi (tanpa persetujuan kasir)'),
                    ]),

                Section::make('Struk')
                    ->description('Cetak dari tablet Android ke printer Bluetooth lewat aplikasi RawBT (Play Store). Pasangkan printer di RawBT sekali saja.')
                    ->columns(2)
                    ->schema([
                        Select::make('struk_lebar')
                            ->label('Lebar kertas')
                            ->options(['58' => '58 mm (32 karakter)', '80' => '80 mm (48 karakter)'])
                            ->required(),
                        Textarea::make('struk_header')
                            ->label('Teks tambahan di kepala')
                            ->placeholder('IG @rentalps · WA 0812...')
                            ->rows(2)
                            ->maxLength(200),
                        Textarea::make('struk_footer')
                            ->label('Teks penutup')
                            ->rows(2)
                            ->maxLength(200)
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function simpan(): void
    {
        $data = $this->form->getState();
        $cabangId = $data['cabang_id'] ?? null;

        if (! $cabangId || ! $this->daftarCabang()->has($cabangId)) {
            Notification::make()->title('Pilih cabang yang valid')->danger()->send();

            return;
        }

        foreach (self::KUNCI as $kunci => [$field]) {
            Pengaturan::simpan($kunci, (int) ($data[$field] ?? 0), $cabangId);
        }

        Pengaturan::simpan('tv.suara_aktif', (bool) ($data['tv_suara_aktif'] ?? true), $cabangId);
        Pengaturan::simpan('tv.ukuran_timer', in_array($data['tv_ukuran_timer'] ?? '', ['kecil', 'sedang', 'besar'], true) ? $data['tv_ukuran_timer'] : 'sedang', $cabangId);
        Pengaturan::simpan('tv.info_teknis', (bool) ($data['tv_info_teknis'] ?? true), $cabangId);
        Pengaturan::simpan('tv.kunci_remote', (bool) ($data['tv_kunci_remote'] ?? true), $cabangId);
        Pengaturan::simpan('tv.warna_timer', isset(StatusTvService::WARNA_TIMER[$data['tv_warna_timer'] ?? '']) ? $data['tv_warna_timer'] : 'putih', $cabangId);
        Pengaturan::simpan('server.url_lokal', StatusTvService::urlServer($data['server_lokal'] ?? null), $cabangId);
        Pengaturan::simpan('server.url_cloud', StatusTvService::urlServer($data['server_cloud'] ?? null), $cabangId);
        Pengaturan::simpan('sesi.pilih_game_otomatis', (bool) ($data['pilih_game_otomatis'] ?? true), $cabangId);
        Pengaturan::simpan('tv.pengumuman', trim((string) ($data['tv_pengumuman'] ?? '')), $cabangId);
        $pesanCepat = collect(preg_split('/\R/u', (string) ($data['tv_pesan_cepat'] ?? '')))
            ->map(fn ($p) => mb_substr(trim($p), 0, PemberitahuanTv::MAKS_TEKS))->filter()->unique()->take(20)->values()->all();
        Pengaturan::simpan('tv.pesan_cepat', $pesanCepat, $cabangId);

        // QRIS
        Pengaturan::simpan('qris.payload', trim((string) ($data['qris_payload'] ?? '')), $cabangId);
        Pengaturan::simpan('qris.aktif', (bool) ($data['qris_aktif'] ?? false) && trim((string) ($data['qris_payload'] ?? '')) !== '', $cabangId);

        // Bayar mandiri di TV
        Pengaturan::simpan('bayar_mandiri.aktif', (bool) ($data['bm_aktif'] ?? false), $cabangId);

        foreach (['minimal_menit', 'masa_qris_menit', 'selesai_otomatis_menit'] as $k) {
            Pengaturan::simpan('bayar_mandiri.'.$k, (int) ($data['bm_'.$k] ?? 0), $cabangId);
        }

        // Booking online
        Pengaturan::simpan('booking.aktif', (bool) ($data['booking_aktif'] ?? false), $cabangId);
        Pengaturan::simpan('booking.konfirmasi_otomatis', (bool) ($data['booking_konfirmasi_otomatis'] ?? false), $cabangId);
        Pengaturan::simpan('operasional.jam_buka', $data['jam_buka'] ?? '10:00', $cabangId);
        Pengaturan::simpan('operasional.jam_tutup', $data['jam_tutup'] ?? '23:00', $cabangId);
        Pengaturan::simpan('operasional.keterangan', trim((string) ($data['jam_keterangan'] ?? '')), $cabangId);
        Pengaturan::simpan('booking.durasi', collect(explode(',', (string) ($data['booking_durasi'] ?? '')))
            ->map(fn ($m) => (int) trim($m))->filter(fn ($m) => $m >= 30 && $m <= 720)->unique()->sort()->values()->all() ?: [60, 120, 180], $cabangId);

        foreach (['min_menit_sebelum', 'maks_hari', 'toleransi_menit', 'jeda_menit', 'maks_aktif_per_hp'] as $k) {
            Pengaturan::simpan('booking.'.$k, (int) ($data['booking_'.$k] ?? 0), $cabangId);
        }
        Pengaturan::simpan('tv.aplikasi', array_values(array_map(
            fn ($a) => ['nama' => trim($a['nama']), 'paket' => trim($a['paket'])],
            $data['tv_aplikasi'] ?? []
        )), $cabangId);
        Pengaturan::simpan('struk.lebar', (int) ($data['struk_lebar'] ?? 58), $cabangId);
        Pengaturan::simpan('struk.header', trim((string) ($data['struk_header'] ?? '')), $cabangId);
        Pengaturan::simpan('struk.footer', trim((string) ($data['struk_footer'] ?? '')), $cabangId);

        // TV di cabang ini langsung memuat pengaturan baru (pengumuman, bypass, suara, dll.)
        NotifikasiTv::cabang($cabangId, 'pengaturan');

        Notification::make()->title('Pengaturan operasional disimpan')->body('TV di cabang ini langsung diperbarui.')->success()->send();
    }
}
