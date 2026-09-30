<?php

namespace App\Filament\Pages;

use App\Services\Gateway\DuitkuGateway;
use App\Services\Gateway\PengaturanGateway;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

/**
 * Payment gateway untuk bayar mandiri di TV (QRIS). Berlaku untuk semua cabang tenant.
 * Aktif/tidaknya per cabang & aturan durasi diatur di Pengaturan → Operasional → Bayar mandiri di TV.
 */
class PengaturanPembayaranOnline extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.pengaturan-pembayaran-online';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 13;

    protected static ?string $navigationLabel = 'Pembayaran online';

    protected static ?string $title = 'Pembayaran online (payment gateway)';

    protected static ?string $slug = 'pembayaran-online';

    /** Isian biasa (bukan rahasia) per gateway */
    private const ISIAN = [
        'tripay_merchant_code', 'tripay_kanal',
        'midtrans_acquirer',
        'duitku_merchant_code', 'duitku_metode',
        'ipaymu_va',
        'doku_client_id', 'doku_metode',
    ];

    private const BAWAAN = ['tripay_kanal' => 'QRIS', 'midtrans_acquirer' => 'gopay', 'duitku_metode' => 'SP', 'doku_metode' => ''];

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->hasRole('Owner'));
    }

    public function mount(PengaturanGateway $g): void
    {
        $isi = ['provider' => $g->provider(), 'sandbox' => $g->sandbox()];

        foreach (self::ISIAN as $k) {
            $isi[$k] = $g->nilai($k, self::BAWAAN[$k] ?? '');
        }

        // Rahasia tidak pernah dikirim ke browser; kosong = tidak diganti
        foreach (PengaturanGateway::RAHASIA as $k) {
            $isi[$k] = '';
        }

        $this->form->fill($isi);
    }

    public function form(Schema $schema): Schema
    {
        $g = app(PengaturanGateway::class);
        $tersimpan = fn (string $k) => $g->nilai($k) ? 'Tersimpan — kosongkan jika tidak diganti' : null;
        $rahasia = fn (string $k, string $label) => TextInput::make($k)->label($label)->password()->revealable()->maxLength(500)
            ->placeholder($tersimpan($k));
        $callback = fn (string $p, string $tempat) => Placeholder::make('callback_'.$p)->label('URL notifikasi / callback ('.$tempat.')')->columnSpanFull()
            ->content(new HtmlString('<code>'.e(url('/api/gateway/'.$p.'/callback')).'</code><br><small>Harus alamat publik (server cloud / domain). Tanpa itu, server lokal tetap mengecek status pembayaran sendiri selama PC terhubung internet.</small>'));
        $untuk = fn (string $p) => fn ($get) => $get('provider') === $p;

        return $schema
            ->components([
                Section::make('Gateway')
                    ->columns(2)
                    ->schema([
                        Select::make('provider')->label('Payment gateway')->options(PengaturanGateway::PROVIDER)->required()->live()
                            ->helperText('Simulasi: untuk uji coba tanpa akun (pembayaran ditandai lunas dari menu kasir Pembayaran online).'),
                        Toggle::make('sandbox')->label('Mode uji (sandbox)')->inline(false)
                            ->helperText('Matikan setelah akun gateway disetujui & siap menerima uang sungguhan. Kunci sandbox & live berbeda.'),
                    ]),

                Section::make('Tripay')
                    ->visible($untuk('tripay'))
                    ->description('Dashboard Tripay → Merchant → Detail.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('tripay_merchant_code')->label('Kode merchant')->maxLength(40),
                        Select::make('tripay_kanal')->label('Kanal QRIS')
                            ->options(['QRIS' => 'QRIS (by ShopeePay)', 'QRISC' => 'QRIS (customizable)', 'QRIS2' => 'QRIS (by DANA)']),
                        $rahasia('tripay_api_key', 'API key'),
                        $rahasia('tripay_private_key', 'Private key'),
                        $callback('tripay', 'isi di dashboard Tripay'),
                    ]),

                Section::make('Midtrans')
                    ->visible($untuk('midtrans'))
                    ->description('Dashboard Midtrans → Settings → Access Keys. Aktifkan metode QRIS di akun Midtrans.')
                    ->columns(2)
                    ->schema([
                        $rahasia('midtrans_server_key', 'Server key'),
                        Select::make('midtrans_acquirer')->label('Penerbit QRIS')
                            ->options(['gopay' => 'GoPay', 'airpay shopee' => 'ShopeePay']),
                        $callback('midtrans', 'Settings → Payment → Notification URL'),
                    ]),

                Section::make('Duitku')
                    ->visible($untuk('duitku'))
                    ->description('Dashboard Duitku → Proyek → kode merchant & API key.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('duitku_merchant_code')->label('Kode merchant')->maxLength(40),
                        Select::make('duitku_metode')->label('Metode QRIS')->options(DuitkuGateway::METODE),
                        $rahasia('duitku_api_key', 'API key'),
                        Placeholder::make('duitku_catatan')->hiddenLabel()
                            ->content('URL callback dikirim otomatis di setiap tagihan (tidak perlu diisi di dashboard).'),
                    ]),

                Section::make('iPaymu')
                    ->visible($untuk('ipaymu'))
                    ->description('Dashboard iPaymu → Integrasi → API key & nomor VA.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('ipaymu_va')->label('Nomor VA')->maxLength(40),
                        $rahasia('ipaymu_api_key', 'API key'),
                        Placeholder::make('ipaymu_catatan')->hiddenLabel()->columnSpanFull()
                            ->content('URL notifikasi dikirim otomatis di setiap tagihan. Notifikasi iPaymu selalu dicek ulang ke API iPaymu sebelum dipercaya.'),
                    ]),

                Section::make('DOKU')
                    ->visible($untuk('doku'))
                    ->description('Dashboard DOKU → Integration → Credentials (Client ID & Secret Key). Memakai DOKU Checkout: TV menampilkan QR tautan pembayaran, HP pelanggan mendapat tombol "Bayar sekarang".')
                    ->columns(2)
                    ->schema([
                        TextInput::make('doku_client_id')->label('Client ID')->maxLength(80),
                        $rahasia('doku_secret_key', 'Secret key'),
                        Select::make('doku_metode')->label('Metode di halaman DOKU')
                            ->options(['' => 'Semua yang aktif di akun DOKU', 'QRIS' => 'Hanya QRIS']),
                        $callback('doku', 'Integration → Notification'),
                    ]),

                Section::make('Winpay')
                    ->visible($untuk('winpay'))
                    ->schema([
                        Placeholder::make('winpay')->hiddenLabel()
                            ->content('Integrasi Winpay (SNAP) diaktifkan setelah akun Winpay dibuat dan dokumen API-nya diterima. Sementara gunakan gateway lain atau Simulasi.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function simpan(PengaturanGateway $g): void
    {
        $d = $this->form->getState();

        $g->simpan('provider', $d['provider']);
        $g->simpan('sandbox', (bool) $d['sandbox']);

        // Isian gateway yang tidak sedang tampil tidak ikut di state → nilai lama dipertahankan
        foreach (self::ISIAN as $k) {
            if (array_key_exists($k, $d)) {
                $g->simpan($k, trim((string) ($d[$k] ?? '')));
            }
        }

        foreach (PengaturanGateway::RAHASIA as $k) {
            if (trim((string) ($d[$k] ?? '')) !== '') {
                $g->simpan($k, trim($d[$k]));
            }
        }

        $this->mount($g);
        Notification::make()->title('Pengaturan pembayaran online disimpan')->success()->send();
    }

    public function tes(PengaturanGateway $g): void
    {
        try {
            if ($g->provider() === 'nonaktif') {
                throw new \RuntimeException('Pilih & simpan gateway dulu.');
            }

            $kanal = $g->driver()->tes();
        } catch (Throwable $e) {
            Notification::make()->title('Tes gagal')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Terhubung ke '.PengaturanGateway::PROVIDER[$g->provider()])
            ->body($kanal ? 'Metode: '.implode(', ', $kanal) : 'Tidak ada kanal QRIS aktif di akun ini.')
            ->{$kanal ? 'success' : 'warning'}()->send();
    }
}
