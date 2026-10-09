<div data-rahasia>
    @php
        $warna = ['tinggi' => 'var(--danger)', 'sedang' => 'var(--status-hampir-habis)', 'info' => 'var(--ikon-biru)'];
        $labelTingkat = ['tinggi' => __('Penting'), 'sedang' => __('Perhatikan'), 'info' => __('Info')];
        $jumlah = $temuan->countBy('tingkat');
        $persen = fn ($v) => $v === null ? '–' : (($v > 0 ? '+' : '').number_format($v, 1, ',', '.').'%');
        $sesi = fn ($n) => __(':n sesi', ['n' => $n]);
    @endphp

    {{-- Judul + periode --}}
    <div class="mb-4">
        <div class="label">{{ __('Analisa pintar · aturan otomatis') }}</div>
        <h1 class="text-xl font-semibold tracking-tight">
            @if ($dariTgl->isSameDay($sampaiTgl))
                {{ $dariTgl->translatedFormat('l, d F Y') }}
            @else
                {{ $dariTgl->translatedFormat('d M Y') }} – {{ $sampaiTgl->translatedFormat('d M Y') }}
            @endif
        </h1>
    </div>

    <div class="flex flex-wrap items-center gap-1.5 mb-4">
        @foreach (\App\Livewire\Concerns\PeriodeLaporan::daftarPeriode() as $kode => $nama)
            <button type="button" wire:click="pilihPeriode('{{ $kode }}')"
                    @class([
                        'btn h-8 px-3 text-xs font-mono uppercase tracking-wider',
                        'btn-primary' => $periode === $kode,
                        'text-muted' => $periode !== $kode,
                    ])>{{ __($nama) }}</button>
        @endforeach
        @if ($periode === 'custom')
            <input type="date" wire:model.live="dari" class="input num w-auto h-8">
            <span class="text-muted">–</span>
            <input type="date" wire:model.live="sampai" class="input num w-auto h-8">
        @endif
        <span wire:loading class="label ml-2">{{ __('Menganalisa...') }}</span>
    </div>

    {{-- Ringkas jumlah temuan --}}
    <div class="grid gap-3 grid-cols-3 mb-4">
        @foreach ($labelTingkat as $t => $l)
            <button type="button" wire:click="pilihTab('temuan')" class="kartu p-3 text-left">
                <div class="label flex items-center gap-1.5" style="color: {{ $warna[$t] }}"><span class="dot"></span>{{ $l }}</div>
                <div class="num text-2xl font-semibold mt-1">{{ $jumlah[$t] ?? 0 }}</div>
            </button>
        @endforeach
    </div>

    {{-- Tab --}}
    <div class="flex gap-1.5 overflow-x-auto pb-1 mb-4">
        @foreach (\App\Livewire\Operator\AnalisaPintar::TAB as $kode => $nama)
            <button type="button" wire:click="pilihTab('{{ $kode }}')"
                    @class([
                        'btn h-8 px-3 text-xs font-mono uppercase tracking-wider shrink-0',
                        'btn-primary' => $tab === $kode,
                        'text-muted' => $tab !== $kode,
                    ])>{{ __($nama) }}</button>
        @endforeach
    </div>

    {{-- ================= Temuan ================= --}}
    @if ($tab === 'temuan')
        @if ($temuan->isEmpty())
            <div class="kartu p-10 text-center text-muted">{{ __('Tidak ada temuan di periode ini. Semua angka dalam batas wajar.') }}</div>
        @else
            <div class="space-y-2.5">
                @foreach ($temuan as $t)
                    <div class="kartu kartu-status p-4" style="--warna-status: {{ $warna[$t['tingkat']] }};">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-semibold">{{ $t['judul'] }}</div>
                                <div class="text-sm text-muted mt-0.5">{{ $t['detail'] }}</div>
                            </div>
                            <span class="chip shrink-0" style="color: {{ $warna[$t['tingkat']] }}">{{ __($t['bagian']) }} · {{ $labelTingkat[$t['tingkat']] }}</span>
                        </div>
                        <div class="text-sm mt-2"><span class="label">{{ __('Saran') }}</span> {{ $t['saran'] }}</div>
                    </div>
                @endforeach
            </div>
        @endif
        <p class="text-xs text-muted mt-4">{{ __('Temuan dihitung otomatis dari data transaksi, kas, log aktivitas & stok dengan ambang tetap. Gunakan sebagai petunjuk awal pemeriksaan, bukan bukti.') }}</p>
    @endif

    {{-- ================= Audit kasir ================= --}}
    @if ($tab === 'kasir')
        <section class="kartu">
            <div class="px-4 py-3 border-b border-line flex flex-wrap justify-between gap-2">
                <div class="font-semibold">{{ __('Skor risiko per kasir') }}</div>
                <div class="label">{{ __('Rata-rata batal :batal% · diskon :diskon%', ['batal' => number_format($kasir['rata_batal'], 1, ',', '.'), 'diskon' => number_format($kasir['rata_diskon'], 1, ',', '.')]) }}</div>
            </div>
            @if ($kasir['baris']->isEmpty())
                <p class="px-4 py-3 text-sm text-muted">{{ __('Belum ada aktivitas kasir di periode ini.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-muted border-b border-line">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium">{{ __('Kasir') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ __('Skor') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ __('Transaksi') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ __('Batal') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ __('Kas kurang') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ __('Bonus waktu') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ __('Bypass TV') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ __('Batal item') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ __('Diskon') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ __('PIN salah') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($kasir['baris'] as $k)
                                @php $w = $k['skor'] >= \App\Services\Analisa\AnalisaService::SKOR_TINGGI ? 'tinggi' : ($k['skor'] >= \App\Services\Analisa\AnalisaService::SKOR_SEDANG ? 'sedang' : null); @endphp
                                <tr>
                                    <td class="px-4 py-2">
                                        <div class="font-medium">{{ $k['nama'] }}</div>
                                        @if ($k['alasan'])
                                            <div class="text-xs text-muted">{{ implode(' · ', $k['alasan']) }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        <span class="num font-semibold" style="color: {{ $w ? $warna[$w] : 'var(--status-kosong)' }}">{{ $k['skor'] }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-right"><span class="num">{{ $k['transaksi'] }}</span><x-rupiah :nilai="$k['omzet']" class="block text-xs text-muted" /></td>
                                    <td class="px-4 py-2 text-right"><span class="num">{{ $k['batal'] }}</span><span class="block text-xs text-muted num">{{ number_format($k['persen_batal'], 1, ',', '.') }}%</span></td>
                                    <td class="px-4 py-2 text-right"><span class="num">{{ $k['kas_kurang_kali'] }}/{{ $k['shift'] }}</span><x-rupiah :nilai="$k['kas_kurang']" class="block text-xs text-muted" /></td>
                                    <td class="px-4 py-2 text-right num">{{ __(':n mnt', ['n' => $k['bonus_menit']]) }}</td>
                                    <td class="px-4 py-2 text-right num">{{ $k['bypass'] }}</td>
                                    <td class="px-4 py-2 text-right num">{{ $k['batal_item'] + $k['sesi_batal'] }}</td>
                                    <td class="px-4 py-2 text-right"><x-rupiah :nilai="$k['diskon']" /><span class="block text-xs text-muted num">{{ number_format($k['persen_diskon'], 1, ',', '.') }}%</span></td>
                                    <td class="px-4 py-2 text-right num">{{ $k['pin_gagal'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
        <p class="text-xs text-muted mt-3">
            {{ __('Skor: batal jauh di atas rata-rata (+25), kas kurang berulang / ≥ Rp50.000 (+25), bonus waktu ≥ 60 menit (+15), bypass TV ≥ 3× (+15), batal F&B/tambah waktu ≥ 5× (+10), sesi batal ≥ 3× (+10), diskon tak wajar (+10), PIN salah ≥ 3× (+10).') }}
            {{ __('≥ :tinggi merah, ≥ :sedang kuning.', ['tinggi' => \App\Services\Analisa\AnalisaService::SKOR_TINGGI, 'sedang' => \App\Services\Analisa\AnalisaService::SKOR_SEDANG]) }}
            {{ __('Rincian kejadian:') }} <a href="{{ url('/admin/audit-log') }}" class="underline">{{ __('Log aktivitas') }}</a>.</p>
    @endif

    {{-- ================= Keuangan ================= --}}
    @if ($tab === 'keuangan')
        @php $r = $keuangan['r']; @endphp
        <div class="grid gap-3 grid-cols-2 lg:grid-cols-4 mb-4">
            <div class="kartu p-4">
                <div class="label">{{ __('Omzet bersih') }}</div>
                <x-rupiah :nilai="$r['omzet_bersih']" class="text-xl font-semibold block mt-1" />
                <div class="text-xs text-muted mt-1">{{ __('vs sebelumnya') }} <span class="num">{{ $persen($keuangan['perubahan']) }}</span></div>
            </div>
            <div class="kartu p-4">
                <div class="label">{{ __('Laba bersih') }}</div>
                <x-rupiah :nilai="$r['laba_bersih']" :class="'text-xl font-semibold block mt-1'.($r['laba_bersih'] < 0 ? ' text-danger' : '')" />
                <div class="text-xs text-muted mt-1">{{ __('Margin') }} <span class="num">{{ number_format($keuangan['margin_bersih'], 1, ',', '.') }}%</span></div>
            </div>
            <div class="kartu p-4">
                <div class="label">{{ __('Omzet / hari') }}</div>
                <x-rupiah :nilai="$keuangan['omzet_harian']" class="text-xl font-semibold block mt-1" />
                <div class="text-xs text-muted mt-1">{{ __('Titik impas') }}
                    @if ($keuangan['bep_harian']) <x-rupiah :nilai="$keuangan['bep_harian']" />/{{ __('hari') }} @else – @endif</div>
            </div>
            <div class="kartu p-4">
                <div class="label">{{ __('Proyeksi akhir bulan') }}</div>
                @if ($keuangan['proyeksi'])
                    <x-rupiah :nilai="$keuangan['proyeksi']['omzet']" class="text-xl font-semibold block mt-1" />
                    <div class="text-xs text-muted mt-1">{{ __('Laba ±') }} <x-rupiah :nilai="$keuangan['proyeksi']['laba']" /> · {{ __('hari :jalan/:bulan', ['jalan' => $keuangan['proyeksi']['hari_jalan'], 'bulan' => $keuangan['proyeksi']['hari_bulan']]) }}</div>
                @else
                    <div class="text-sm text-muted mt-2">{{ __('Pilih periode "Bulan ini"') }}</div>
                @endif
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="kartu">
                <div class="px-4 py-3 border-b border-line font-semibold">{{ __('Rasio kesehatan') }}</div>
                <dl class="px-4 py-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Margin laba kotor') }}</dt><dd class="num">{{ number_format($keuangan['margin_kotor'], 1, ',', '.') }}%</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Margin F&B') }}</dt><dd class="num">{{ $keuangan['margin_fnb'] === null ? '–' : number_format($keuangan['margin_fnb'], 1, ',', '.').'%' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Pengeluaran operasional ÷ omzet') }}</dt><dd class="num">{{ number_format($keuangan['rasio_beban'], 1, ',', '.') }}%</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Diskon ÷ omzet kotor') }}</dt><dd class="num">{{ $r['omzet_kotor'] > 0 ? number_format($r['diskon'] * 100 / $r['omzet_kotor'], 1, ',', '.') : '0,0' }}%</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Pembatalan') }}</dt><dd><span class="num">{{ $r['batal_jumlah'] }}×</span> · <x-rupiah :nilai="$r['batal_nilai']" /></dd></div>
                </dl>
            </section>

            <section class="kartu">
                <div class="px-4 py-3 border-b border-line font-semibold">{{ __('Aset & balik modal') }}</div>
                @if ($a = $keuangan['aset'])
                    <dl class="px-4 py-3 space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-muted">{{ __('Investasi aset') }}</dt><dd><x-rupiah :nilai="$a['investasi']" /></dd></div>
                        <div class="flex justify-between"><dt class="text-muted">{{ __('Laba sejak awal') }}</dt><dd><x-rupiah :nilai="$a['laba']" /></dd></div>
                        <div class="flex justify-between"><dt class="text-muted">{{ __('ROI') }}</dt><dd class="num">{{ number_format($a['roi'], 1, ',', '.') }}%</dd></div>
                        <div class="flex justify-between"><dt class="text-muted">{{ __('Perkiraan balik modal') }}</dt>
                            <dd class="num">{{ $a['balik_modal_bulan'] === 0 ? __('Sudah balik modal') : ($a['balik_modal_bulan'] === null ? '–' : __('± :n bulan lagi', ['n' => $a['balik_modal_bulan']])) }}</dd></div>
                    </dl>
                @else
                    <p class="px-4 py-3 text-sm text-muted">{{ __('Catat aset (PS, TV, PC) di menu Aset & Modal untuk menghitung ROI & balik modal.') }}</p>
                @endif
            </section>

            <section class="kartu lg:col-span-2">
                <div class="px-4 py-3 border-b border-line font-semibold">{{ __('Pengeluaran melonjak') }}</div>
                @if ($keuangan['lonjakan']->isEmpty())
                    <p class="px-4 py-3 text-sm text-muted">{{ __('Tidak ada kategori pengeluaran yang melonjak dibanding 3 periode sebelumnya.') }}</p>
                @else
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($keuangan['lonjakan'] as $p)
                            <li class="px-4 py-2 flex justify-between gap-3">
                                <span>{{ __($p['kategori']) }}</span>
                                <span><x-rupiah :nilai="$p['total']" class="text-danger" /> <span class="text-muted text-xs">{{ __('rata-rata') }} <x-rupiah :nilai="$p['rata']" /></span></span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    @endif

    {{-- ================= Operasional ================= --}}
    @if ($tab === 'operasional')
        @php
            $maksJam = max(1, max($operasional['per_jam']));
            $maksHari = max(1, $operasional['hari_minggu']->max('omzet'));
            $m = $operasional['member'];
        @endphp
        <div class="grid gap-4 lg:grid-cols-2">
            <section class="kartu">
                <div class="px-4 py-3 border-b border-line flex justify-between items-center">
                    <div class="font-semibold">{{ __('Utilisasi unit') }}</div>
                    <div class="label">{{ __('Jam buka :buka–:tutup · rata-rata :persen%', ['buka' => $operasional['jam_buka']['buka'], 'tutup' => $operasional['jam_buka']['tutup'], 'persen' => number_format($operasional['rata_utilisasi'], 1, ',', '.')]) }}</div>
                </div>
                <div class="px-4 py-3 space-y-2.5">
                    @forelse ($operasional['unit'] as $u)
                        <div>
                            <div class="flex justify-between text-sm mb-1"><span>{{ $u['kode'] }} <span class="text-muted">· {{ $sesi($u['sesi']) }}</span></span><span class="num">{{ number_format($u['utilisasi'], 1, ',', '.') }}%</span></div>
                            <div class="h-2 rounded-full bg-bg border border-line overflow-hidden">
                                <div class="h-full bg-st-main rounded-full" style="width: {{ min(100, $u['utilisasi']) }}%"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">{{ __('Belum ada unit.') }}</p>
                    @endforelse
                </div>
            </section>

            <section class="kartu">
                <div class="px-4 py-3 border-b border-line font-semibold">{{ __('Sesi per jam (jam buka)') }}</div>
                <div class="px-4 py-4">
                    <div class="flex items-end gap-1 h-32">
                        @foreach ($operasional['jam_buka_list'] as $j)
                            @php $n = $operasional['per_jam'][$j]; @endphp
                            <div class="flex-1 flex flex-col items-center justify-end h-full" title="{{ sprintf('%02d:00', $j) }} · {{ $sesi($n) }}">
                                <div class="w-full rounded-t-sm {{ $n <= $maksJam * 0.2 ? 'bg-line' : 'bg-accent' }}" style="height: {{ max(2, round($n / $maksJam * 100)) }}%"></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="flex gap-1 mt-1.5">
                        @foreach ($operasional['jam_buka_list'] as $j)
                            <div class="flex-1 text-center label" style="font-size: 9px;">{{ sprintf('%02d', $j) }}</div>
                        @endforeach
                    </div>
                    <p class="text-xs text-muted mt-2">{{ __('Batang abu-abu = jam sepi (≤ 20% jam tersibuk).') }}</p>
                </div>
            </section>

            <section class="kartu">
                <div class="px-4 py-3 border-b border-line font-semibold">{{ __('Omzet per hari') }}</div>
                <div class="px-4 py-3 space-y-2.5">
                    @foreach ($operasional['hari_minggu'] as $h)
                        <div>
                            <div class="flex justify-between text-sm mb-1"><span>{{ $h['hari'] }}</span><x-rupiah :nilai="$h['omzet']" /></div>
                            <div class="h-2 rounded-full bg-bg border border-line overflow-hidden">
                                <div class="h-full bg-accent rounded-full" style="width: {{ round($h['omzet'] / $maksHari * 100) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="kartu">
                <div class="px-4 py-3 border-b border-line font-semibold">{{ __('Paket terlaris & member') }}</div>
                <ol class="divide-y divide-line text-sm">
                    @forelse ($operasional['paket'] as $i => $p)
                        <li class="px-4 py-2 flex justify-between gap-3"><span><span class="label w-5 inline-block">{{ $i + 1 }}</span> {{ $p['nama'] }}</span><span class="num">{{ $sesi($p['sesi']) }}</span></li>
                    @empty
                        <li class="px-4 py-2 text-muted">{{ __('Belum ada sesi.') }}</li>
                    @endforelse
                </ol>
                <dl class="px-4 py-3 border-t border-line space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Member aktif (datang ≤ 30 hari)') }}</dt><dd class="num">{{ $m['aktif'] }} / {{ $m['total'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Member tidur (> 30 hari)') }}</dt><dd class="num">{{ $m['tidur'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Porsi omzet dari member') }}</dt><dd class="num">{{ number_format($m['persen_omzet'], 1, ',', '.') }}%</dd></div>
                </dl>
            </section>
        </div>
    @endif

    {{-- ================= Stok ================= --}}
    @if ($tab === 'stok')
        <div class="grid gap-4 lg:grid-cols-2">
            <section class="kartu">
                <div class="px-4 py-3 border-b border-line font-semibold">{{ __('Hilang saat opname') }}</div>
                @if ($stok['hilang']->isEmpty())
                    <p class="px-4 py-3 text-sm text-muted">{{ __('Tidak ada selisih kurang saat opname di periode ini.') }}</p>
                @else
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($stok['hilang'] as $h)
                            <li class="px-4 py-2 flex justify-between gap-3">
                                <span class="min-w-0"><span class="block truncate">{{ $h['produk'] }}</span><span class="text-xs text-muted">{{ __('opname: :nama', ['nama' => $h['oleh']]) }}</span></span>
                                <span class="text-right shrink-0"><span class="num text-danger">−{{ $h['qty'] }}</span><x-rupiah :nilai="$h['nilai']" class="block text-xs text-muted" /></span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="kartu">
                <div class="px-4 py-3 border-b border-line font-semibold">{{ __('Segera habis (≤ 3 hari)') }}</div>
                @if ($stok['habis']->isEmpty())
                    <p class="px-4 py-3 text-sm text-muted">{{ __('Tidak ada produk laris yang akan habis dalam 3 hari.') }}</p>
                @else
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($stok['habis'] as $h)
                            <li class="px-4 py-2 flex justify-between gap-3">
                                <span class="truncate">{{ $h['produk'] }}</span>
                                <span class="num text-right shrink-0">{{ __('sisa :qty · :laju/hari · ±:hari hari', ['qty' => $h['qty'], 'laju' => number_format($h['per_hari'], 1, ',', '.'), 'hari' => $h['sisa_hari']]) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="kartu">
                <div class="px-4 py-3 border-b border-line font-semibold">{{ __('Margin tipis / rugi (< 15%)') }}</div>
                @if ($stok['margin']->isEmpty())
                    <p class="px-4 py-3 text-sm text-muted">{{ __('Semua produk bermargin ≥ 15%.') }}</p>
                @else
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($stok['margin'] as $mg)
                            <li class="px-4 py-2 flex justify-between gap-3">
                                <span class="truncate">{{ $mg['produk'] }}</span>
                                <span class="text-right shrink-0">
                                    <span @class(['num', 'text-danger' => $mg['margin'] <= 0])>{{ number_format($mg['margin'], 1, ',', '.') }}%</span>
                                    <span class="block text-xs text-muted">{{ __('jual') }} <x-rupiah :nilai="$mg['harga_jual']" /> · {{ __('pokok') }} <x-rupiah :nilai="$mg['hpp']" /></span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="kartu">
                <div class="px-4 py-3 border-b border-line font-semibold">{{ __('Stok mati (tidak laku 30 hari)') }}</div>
                @if ($stok['mati']->isEmpty())
                    <p class="px-4 py-3 text-sm text-muted">{{ __('Semua produk berstok terjual dalam 30 hari terakhir.') }}</p>
                @else
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($stok['mati'] as $md)
                            <li class="px-4 py-2 flex justify-between gap-3">
                                <span class="truncate">{{ $md['produk'] }}</span>
                                <span class="text-right shrink-0"><span class="num">{{ $md['qty'] }}</span> · <x-rupiah :nilai="$md['nilai']" class="text-muted" /></span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    @endif
</div>
