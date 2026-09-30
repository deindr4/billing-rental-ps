<?php

namespace App\Filament\Pages;

use App\Jobs\KirimNotifikasi;
use App\Models\Cabang;
use App\Models\NotifikasiLog;
use App\Services\Notifikasi\PengaturanNotifikasi as Setelan;
use App\Services\Notifikasi\WhatsappService;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Throwable;
use UnitEnum;

/**
 * Pengaturan laporan otomatis: Telegram (bot token + ID grup) & WhatsApp (login QR).
 */
class PengaturanNotifikasi extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.pengaturan-notifikasi';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'Notifikasi & Laporan';

    protected static ?string $title = 'Notifikasi & Laporan Otomatis';

    public ?array $data = [];

    /** Daftar grup WhatsApp (untuk dipilih sebagai tujuan) */
    public array $grupWa = [];

    public bool $tampilGrup = false;

    public string $cariGrup = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('admin.pengaturan');
    }

    public function mount(): void
    {
        $this->muatCabang(app(Tenancy::class)->cabangId() ?? $this->daftarCabang()->keys()->first());
    }

    private function daftarCabang(): Collection
    {
        return Cabang::query()
            ->where('tenant_id', app(Tenancy::class)->tenantId())
            ->orderBy('kode')
            ->pluck('nama', 'id');
    }

    public function muatCabang(?string $cabangId): void
    {
        $nilai = $cabangId ? Setelan::untuk($cabangId)->nilaiForm() : [];
        $this->form->fill(['cabang_id' => $cabangId] + $nilai);
    }

    private function setelan(): ?Setelan
    {
        $cabangId = $this->data['cabang_id'] ?? null;

        return $cabangId && $this->daftarCabang()->has($cabangId) ? Setelan::untuk($cabangId) : null;
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
                            ->helperText('Setiap cabang bisa dikirim ke grup yang berbeda.'),
                    ]),

                Section::make('Telegram')
                    ->description('Buat bot lewat @BotFather, masukkan bot ke grup, lalu isi token & ID grup.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('telegram_aktif')->label('Aktifkan Telegram')->columnSpanFull(),
                        TextInput::make('telegram_token')
                            ->label('Token bot')
                            ->password()
                            ->revealable()
                            ->placeholder(fn () => $this->setelan()?->adaTokenTelegram() ? 'Tersimpan — kosongkan jika tidak diganti' : '123456789:AA...')
                            ->helperText('Disimpan terenkripsi.'),
                        TextInput::make('telegram_chat_id')
                            ->label('ID grup')
                            ->placeholder('-1001234567890')
                            ->helperText('ID grup diawali tanda minus.'),
                        TextInput::make('telegram_topic_id')
                            ->label('ID topik (opsional)')
                            ->helperText('Hanya untuk grup yang memakai Topics.'),
                    ]),

                Section::make('WhatsApp')
                    ->description('Login cukup scan QR (seperti WhatsApp Web). Gunakan nomor khusus rental.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('wa_aktif')->label('Aktifkan WhatsApp')->columnSpanFull(),
                        TextInput::make('wa_tujuan')
                            ->label('Tujuan')
                            ->placeholder('ID grup (…@g.us) atau nomor 08xxx')
                            ->helperText('Pilih dari daftar grup di bawah, atau isi nomor tujuan.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Laporan tutup kas')
                    ->columns(2)
                    ->schema([
                        Toggle::make('laporan_tutup_kas')->label('Kirim otomatis setiap tutup kas'),
                        Toggle::make('laporan_pdf')->label('Lampirkan PDF detail transaksi'),
                    ]),
            ])
            ->statePath('data');
    }

    public function simpan(): void
    {
        $data = $this->form->getState();
        $setelan = $this->setelan();

        if (! $setelan) {
            Notification::make()->title('Pilih cabang yang valid')->danger()->send();

            return;
        }

        if (filled($data['telegram_token'] ?? null)) {
            $setelan->simpanTelegramToken($data['telegram_token']);
        }

        $setelan->simpan('telegram.aktif', (bool) ($data['telegram_aktif'] ?? false));
        $setelan->simpan('telegram.chat_id', trim((string) ($data['telegram_chat_id'] ?? '')) ?: null);
        $setelan->simpan('telegram.topic_id', trim((string) ($data['telegram_topic_id'] ?? '')) ?: null);
        $setelan->simpan('wa.aktif', (bool) ($data['wa_aktif'] ?? false));
        $setelan->simpan('wa.tujuan', trim((string) ($data['wa_tujuan'] ?? '')) ?: null);
        $setelan->simpan('laporan.tutup_kas', (bool) ($data['laporan_tutup_kas'] ?? false));
        $setelan->simpan('laporan.pdf', (bool) ($data['laporan_pdf'] ?? false));

        $this->muatCabang($data['cabang_id']);

        Notification::make()->title('Pengaturan notifikasi disimpan')->success()->send();
    }

    /* ---------------- Tes kirim ---------------- */

    public function tesKirim(string $saluran): void
    {
        $setelan = $this->setelan();

        if (! $setelan) {
            return;
        }

        $aktif = $saluran === 'telegram' ? $setelan->telegramAktif() : $setelan->waAktif();

        if (! $aktif) {
            Notification::make()->title('Simpan & aktifkan '.ucfirst($saluran).' dulu')->warning()->send();

            return;
        }

        KirimNotifikasi::antrekan(
            $saluran,
            app(Tenancy::class)->tenantId(),
            $this->data['cabang_id'],
            'tes',
            '✅ Tes notifikasi dari '.config('app.name')."\nCabang: ".$this->daftarCabang()[$this->data['cabang_id']]."\nWaktu: ".now()->format('d/m/Y H:i')
        );

        Notification::make()
            ->title('Pesan tes diantrekan')
            ->body('Pastikan "php artisan queue:work" berjalan. Cek status di riwayat pengiriman.')
            ->success()
            ->send();
    }

    /* ---------------- WhatsApp ---------------- */

    public function muatGrupWa(WhatsappService $wa): void
    {
        try {
            $this->grupWa = $wa->grup();
            $this->cariGrup = '';
            $this->tampilGrup = true;
        } catch (Throwable $e) {
            Notification::make()->title('Gagal memuat grup')->body($e->getMessage())->danger()->send();
        }
    }

    public function sembunyikanGrup(): void
    {
        $this->tampilGrup = false;
        $this->cariGrup = '';
    }

    /** Grup yang cocok dengan pencarian */
    public function grupTersaring(): array
    {
        $kata = mb_strtolower(trim($this->cariGrup));

        if ($kata === '') {
            return $this->grupWa;
        }

        return array_values(array_filter(
            $this->grupWa,
            fn ($g) => str_contains(mb_strtolower($g['nama']), $kata) || str_contains($g['id'], $kata)
        ));
    }

    public function pilihGrupWa(string $id): void
    {
        $this->data['wa_tujuan'] = $id;
        $nama = collect($this->grupWa)->firstWhere('id', $id)['nama'] ?? $id;
        $this->sembunyikanGrup();

        Notification::make()->title("Tujuan: {$nama}")->body('Klik Simpan untuk menyimpan.')->success()->send();
    }

    public function logoutWa(WhatsappService $wa): void
    {
        $wa->logout();
        Notification::make()->title('WhatsApp logout, scan QR lagi untuk login')->success()->send();
    }

    protected function getViewData(): array
    {
        return [
            'wa' => app(WhatsappService::class)->status(),
            'riwayat' => NotifikasiLog::query()
                ->with('cabang:id,kode')
                ->latest()
                ->limit(15)
                ->get(),
        ];
    }
}
