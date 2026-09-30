<?php

namespace App\Filament\Pages;

use App\Models\Member;
use App\Models\Pengaturan;
use App\Services\Member\MemberService;
use App\Services\Member\PengaturanMember as Aturan;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * Aturan program member (berlaku untuk semua cabang): tier, poin, stamp, bonus top up.
 */
class PengaturanMember extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.pengaturan-member';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'Member';

    protected static ?string $title = 'Program member';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('admin.pengaturan');
    }

    public function mount(): void
    {
        $a = app(Aturan::class);

        $this->form->fill([
            'aktif' => $a->aktif(),
            'tier' => $a->tier(),
            'belanja_per_poin' => $a->belanjaPerPoin(),
            'nilai_poin' => $a->nilaiPoin(),
            'min_tukar_poin' => $a->minTukarPoin(),
            'target_stamp' => $a->targetStamp(),
            'hadiah_stamp_menit' => $a->hadiahStampMenit(),
            'min_belanja_stamp' => $a->minBelanjaStamp(),
            'min_topup' => $a->minTopUp(),
            'bonus_topup' => collect($a->bonusTopUp())->sortBy('min')->values()->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Program member')
                    ->schema([
                        Toggle::make('aktif')
                            ->label('Aktifkan program member')
                            ->helperText('Jika mati: kasir tidak bisa memilih member, diskon tier & tukar poin berhenti. Saldo tetap bisa dipakai.'),
                    ]),

                Section::make('Tier (level member)')
                    ->description('Naik otomatis berdasarkan total belanja seumur hidup. Diskon hanya untuk biaya sewa (bukan F&B).')
                    ->schema([
                        Repeater::make('tier')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('nama')->label('Nama tier')->required()->maxLength(30),
                                TextInput::make('min_belanja')->label('Minimal total belanja (Rp)')->numeric()->minValue(0)->required(),
                                TextInput::make('diskon_persen')->label('Diskon sewa (%)')->numeric()->minValue(0)->maxValue(100)->required(),
                            ])
                            ->columns(3)
                            ->minItems(1)
                            ->maxItems(8)
                            ->addActionLabel('Tambah tier')
                            ->helperText('Tier dengan minimal Rp0 adalah level awal member baru.'),
                    ]),

                Section::make('Poin')
                    ->columns(3)
                    ->schema([
                        TextInput::make('belanja_per_poin')->label('Belanja untuk 1 poin (Rp)')->numeric()->minValue(0)->required()
                            ->helperText('0 = member tidak mendapat poin.'),
                        TextInput::make('nilai_poin')->label('Nilai 1 poin saat ditukar (Rp)')->numeric()->minValue(0)->required()
                            ->helperText('0 = poin tidak bisa ditukar.'),
                        TextInput::make('min_tukar_poin')->label('Minimal poin sekali tukar')->numeric()->minValue(1)->required(),
                    ]),

                Section::make('Stamp (kartu main)')
                    ->description('1 stamp setiap sesi rental lunas. Stamp penuh bisa ditukar main gratis.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('target_stamp')->label('Jumlah stamp untuk 1 hadiah')->numeric()->minValue(0)->required()
                            ->helperText('0 = stamp tidak aktif.'),
                        TextInput::make('hadiah_stamp_menit')->label('Hadiah: main gratis (menit)')->numeric()->minValue(0)->maxValue(720)->required(),
                        TextInput::make('min_belanja_stamp')->label('Minimal tagihan untuk dapat stamp (Rp)')->numeric()->minValue(0)->required(),
                    ]),

                Section::make('Saldo & top up')
                    ->description('Top up dicatat sebagai titipan (bukan omzet). Omzet diakui saat saldo dipakai.')
                    ->schema([
                        TextInput::make('min_topup')->label('Minimal top up (Rp)')->numeric()->minValue(1000)->required(),
                        Repeater::make('bonus_topup')
                            ->label('Bonus top up')
                            ->schema([
                                TextInput::make('min')->label('Top up minimal (Rp)')->numeric()->minValue(1000)->required(),
                                TextInput::make('bonus')->label('Bonus saldo (Rp)')->numeric()->minValue(1)->required(),
                            ])
                            ->columns(2)
                            ->maxItems(6)
                            ->defaultItems(0)
                            ->addActionLabel('Tambah bonus')
                            ->helperText('Contoh: top up Rp100.000 dapat bonus Rp10.000. Yang dipakai bonus terbesar yang memenuhi.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function simpan(): void
    {
        $d = $this->form->getState();

        $tier = collect($d['tier'] ?? [])
            ->map(fn ($t) => ['nama' => trim($t['nama']), 'min_belanja' => (int) $t['min_belanja'], 'diskon_persen' => (int) $t['diskon_persen']])
            ->sortBy('min_belanja')
            ->values();

        if ($tier->isEmpty() || $tier->first()['min_belanja'] !== 0) {
            Notification::make()->title('Harus ada tier dengan minimal belanja Rp0')->danger()->send();

            return;
        }

        if ($tier->pluck('nama')->map(fn ($n) => mb_strtolower($n))->duplicates()->isNotEmpty()) {
            Notification::make()->title('Nama tier tidak boleh sama')->danger()->send();

            return;
        }

        Pengaturan::simpan('member.aktif', (bool) $d['aktif']);
        Pengaturan::simpan('member.tier', $tier->all());
        Pengaturan::simpan('member.belanja_per_poin', (int) $d['belanja_per_poin']);
        Pengaturan::simpan('member.nilai_poin', (int) $d['nilai_poin']);
        Pengaturan::simpan('member.min_tukar_poin', (int) $d['min_tukar_poin']);
        Pengaturan::simpan('member.target_stamp', (int) $d['target_stamp']);
        Pengaturan::simpan('member.hadiah_stamp_menit', (int) $d['hadiah_stamp_menit']);
        Pengaturan::simpan('member.min_belanja_stamp', (int) $d['min_belanja_stamp']);
        Pengaturan::simpan('member.min_topup', (int) $d['min_topup']);
        Pengaturan::simpan('member.bonus_topup', collect($d['bonus_topup'] ?? [])
            ->map(fn ($b) => ['min' => (int) $b['min'], 'bonus' => (int) $b['bonus']])->values()->all());

        // Tier semua member disesuaikan dengan aturan baru
        $service = app(MemberService::class);
        $berubah = 0;

        Member::query()->select(['id', 'tier', 'total_belanja'])->chunkById(500, function ($members) use ($service, &$berubah) {
            foreach ($members as $m) {
                $baru = $service->tierUntuk((int) $m->total_belanja)['nama'];

                if ($baru !== $m->tier) {
                    Member::whereKey($m->id)->update(['tier' => $baru]);
                    $berubah++;
                }
            }
        });

        $this->mount();

        Notification::make()
            ->title('Program member disimpan')
            ->body($berubah > 0 ? "{$berubah} member pindah tier sesuai aturan baru." : null)
            ->success()
            ->send();
    }
}
