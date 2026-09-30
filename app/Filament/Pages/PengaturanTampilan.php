<?php

namespace App\Filament\Pages;

use App\Models\Pengaturan;
use App\Services\Tv\NotifikasiTv;
use App\Support\Gambar;
use App\Support\Tema;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;
use UnitEnum;

/**
 * Tema aplikasi operator, billboard & TV: mode, warna aksen, logo (per tenant).
 */
class PengaturanTampilan extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.pengaturan-tampilan';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Tampilan';

    protected static ?string $title = 'Tampilan';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('admin.pengaturan');
    }

    public function mount(): void
    {
        $this->form->fill([
            'mode' => Tema::mode(),
            'aksen' => Tema::aksen(),
            'logo' => Pengaturan::ambil('tema.logo'),
            'wallpaper' => Pengaturan::ambil('tv.wallpaper'),
            'gelap_wallpaper' => (int) Pengaturan::ambil('tv.transparansi_lock', 70),
        ]);
    }

    private function folderUpload(): string
    {
        return 'tenants/'.app(Tenancy::class)->tenantId().'/upload';
    }

    public function form(Schema $schema): Schema
    {
        $preset = collect(Tema::PRESET)->map(fn ($hex, $nama) => "{$nama} {$hex}")->implode(' · ');

        return $schema
            ->components([
                Section::make('Mode & warna')
                    ->description('Berlaku untuk semua cabang, aplikasi kasir, billboard, dan layar TV.')
                    ->columns(2)
                    ->schema([
                        Radio::make('mode')
                            ->label('Mode tampilan')
                            ->options(Tema::MODE)
                            ->required(),
                        ColorPicker::make('aksen')
                            ->label('Warna aksen')
                            ->regex('/^#[0-9a-fA-F]{6}$/')
                            ->helperText("Preset: {$preset}. Warna status unit & merah untuk aksi berbahaya tidak ikut berubah.")
                            ->required(),
                    ]),

                Section::make('Logo')
                    ->schema([
                        FileUpload::make('logo')
                            ->label('Logo rental')
                            ->image()
                            ->disk('public')
                            ->directory(fn () => $this->folderUpload())
                            ->maxSize(2048)
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                            ->helperText('PNG/JPG/WebP, maks. 2 MB. Otomatis diperkecil & dikonversi ke WebP. Disarankan latar transparan.'),
                    ]),

                Section::make('Layar kunci TV')
                    ->description('Gambar latar di layar "Ready to play" semua TV. Bisa diganti per unit di Admin → Unit.')
                    ->columns(2)
                    ->schema([
                        FileUpload::make('wallpaper')
                            ->label('Wallpaper')
                            ->image()
                            ->disk('public')
                            ->directory(fn () => $this->folderUpload())
                            ->maxSize(8192)
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                            ->imageEditor()
                            ->imageEditorAspectRatios(['16:9'])
                            ->helperText('Rasio 16:9 (mis. 1920×1080), maks. 8 MB. Otomatis diperkecil & dikonversi ke WebP.')
                            ->columnSpanFull(),
                        TextInput::make('gelap_wallpaper')
                            ->label('Kegelapan lapisan di atas gambar (%)')
                            ->helperText('Supaya teks tetap terbaca. 0 = gambar terang apa adanya, 90 = hampir hitam. Disarankan 55–75.')
                            ->numeric()->minValue(0)->maxValue(95)->required(),
                    ]),
            ])
            ->statePath('data');
    }

    public function simpan(): void
    {
        $data = $this->form->getState();
        $tenantId = app(Tenancy::class)->tenantId();
        $logoLama = Pengaturan::ambil('tema.logo');
        $logoBaru = $data['logo'] ?? null;

        try {
            if ($logoBaru && $logoBaru !== $logoLama) {
                // File baru diunggah: kompres ke WebP lalu hapus file asli
                $disk = Storage::disk('public');
                $path = Tema::simpanLogo($disk->path($logoBaru), $tenantId);
                $disk->delete($logoBaru);
                Tema::hapusFile($logoLama);
                Pengaturan::simpan('tema.logo', $path);
            } elseif (! $logoBaru && $logoLama) {
                Tema::hapusFile($logoLama);
                Pengaturan::simpan('tema.logo', null);
            }
        } catch (Throwable $e) {
            Notification::make()->title('Logo gagal diproses')->body($e->getMessage())->danger()->send();

            return;
        }

        // Wallpaper layar kunci TV: kompres ke WebP 1920px, hapus file lama
        $wallpaperLama = Pengaturan::ambil('tv.wallpaper');
        $wallpaperBaru = $data['wallpaper'] ?? null;

        try {
            if ($wallpaperBaru && $wallpaperBaru !== $wallpaperLama) {
                $disk = Storage::disk('public');
                $path = Gambar::simpanWebp($disk->path($wallpaperBaru), 'tenants/'.$tenantId.'/wallpaper', ...Gambar::WALLPAPER);
                $disk->delete($wallpaperBaru);
                Tema::hapusFile($wallpaperLama);
                Pengaturan::simpan('tv.wallpaper', $path);
            } elseif (! $wallpaperBaru && $wallpaperLama) {
                Tema::hapusFile($wallpaperLama);
                Pengaturan::simpan('tv.wallpaper', null);
            }
        } catch (Throwable $e) {
            Notification::make()->title('Wallpaper gagal diproses')->body($e->getMessage())->danger()->send();

            return;
        }

        Pengaturan::simpan('tv.transparansi_lock', max(0, min(95, (int) $data['gelap_wallpaper'])));

        Pengaturan::simpan('tema.mode', $data['mode']);
        Pengaturan::simpan('tema.aksen', strtolower($data['aksen']));
        Pengaturan::simpan('tema.aksen_kontras', Tema::kontras($data['aksen']));

        $this->mount();

        // Semua TV langsung memuat logo, warna & wallpaper baru
        NotifikasiTv::tenant($tenantId, 'tampilan');

        $peringatan = Tema::peringatan($data['aksen'], $data['mode']);

        Notification::make()
            ->title('Tampilan disimpan')
            ->body($peringatan ?? 'Muat ulang aplikasi kasir untuk melihat perubahan.')
            ->success()
            ->send();
    }
}
