{{--
    Isi laporan (dipakai halaman Laporan operator & admin).
    Variabel: $dariTgl, $sampaiTgl, $hari, $r, $lalu, $perMetode, $jamSibuk, $perUnit, $maksUnit,
              $produk, $operator, $pengeluaran, $lihatLaba, $perCabang (opsional)
--}}
@php
    $persen = function (int $kini, int $lalu) {
        if ($lalu === 0) {
            return $kini > 0 ? null : 0;
        }

        return (int) round(($kini - $lalu) / abs($lalu) * 100);
    };
    $naik = $persen($r['omzet_bersih'], $lalu['omzet_bersih']);
    $maksJam = max(1, max($jamSibuk));
    $jamPuncak = array_search(max($jamSibuk), $jamSibuk, true);
    $metodeLabel = \App\Models\Pembayaran::LABEL;
    $totalMetode = max(1, (int) $perMetode->sum('total'));
@endphp

{{-- ================= KPI ================= --}}
<div class="grid gap-3 grid-cols-2 lg:grid-cols-4 mb-4">
    <div class="kartu p-4">
        <div class="label">Omzet bersih</div>
        <x-rupiah :nilai="$r['omzet_bersih']" class="text-xl font-semibold block mt-1 text-accent" />
        <div class="text-xs mt-1">
            @if ($naik === null)
                <span class="text-muted">Periode lalu Rp 0</span>
            @else
                <span class="{{ $naik >= 0 ? 'text-accent' : 'text-danger' }} num">{{ $naik >= 0 ? '▲' : '▼' }} {{ abs($naik) }}%</span>
                <span class="text-muted">vs {{ $hari }} hari sebelumnya</span>
            @endif
        </div>
    </div>
    <div class="kartu p-4">
        <div class="label">Transaksi</div>
        <div class="num text-xl font-semibold mt-1">{{ number_format($r['jumlah_transaksi'], 0, ',', '.') }}</div>
        <div class="text-xs text-muted mt-1">Rata-rata <x-rupiah :nilai="$r['rata_rata']" /></div>
    </div>
    @if ($lihatLaba)
        <div class="kartu p-4">
            <div class="label">Laba bersih</div>
            <x-rupiah :nilai="$r['laba_bersih']" class="text-xl font-semibold block mt-1 {{ $r['laba_bersih'] >= 0 ? '' : 'text-danger' }}" />
            <div class="text-xs text-muted mt-1">
                Margin {{ $r['omzet_bersih'] > 0 ? round($r['laba_bersih'] / $r['omzet_bersih'] * 100) : 0 }}%
            </div>
        </div>
    @endif
    <div class="kartu p-4">
        <div class="label">Pengeluaran</div>
        <x-rupiah :nilai="$r['beban']" class="text-xl font-semibold block mt-1 text-danger" />
        <div class="text-xs text-muted mt-1">
            @if ($r['belanja_stok'] > 0)
                + belanja stok <x-rupiah :nilai="$r['belanja_stok']" />
            @else
                Tanpa belanja stok
            @endif
        </div>
    </div>
    @unless ($lihatLaba)
        <div class="kartu p-4">
            <div class="label">Dibatalkan</div>
            <div class="num text-xl font-semibold mt-1 {{ $r['batal_jumlah'] > 0 ? 'text-danger' : '' }}">{{ $r['batal_jumlah'] }}</div>
            <div class="text-xs text-muted mt-1">Nilai <x-rupiah :nilai="$r['batal_nilai']" /></div>
        </div>
    @endunless
</div>

{{-- ================= Perbandingan cabang (admin, semua cabang) ================= --}}
@if (! empty($perCabang) && count($perCabang) > 1)
    <section class="kartu mb-4">
        <div class="px-4 py-3 border-b border-line font-semibold">Perbandingan cabang</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-muted border-b border-line">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">Cabang</th>
                        <th class="px-4 py-2 text-right font-medium">Transaksi</th>
                        <th class="px-4 py-2 text-right font-medium">Omzet bersih</th>
                        <th class="px-4 py-2 text-right font-medium">Pengeluaran</th>
                        @if ($lihatLaba)
                            <th class="px-4 py-2 text-right font-medium">Laba bersih</th>
                        @endif
                        <th class="px-4 py-2 text-left font-medium w-1/4">Porsi omzet</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @php $maksOmzet = max(1, collect($perCabang)->max(fn ($c) => $c['r']['omzet_bersih'])); @endphp
                    @foreach ($perCabang as $c)
                        <tr>
                            <td class="px-4 py-2 font-medium">{{ $c['nama'] }} <span class="chip ml-1">{{ $c['kode'] }}</span></td>
                            <td class="px-4 py-2 text-right num">{{ $c['r']['jumlah_transaksi'] }}</td>
                            <td class="px-4 py-2 text-right"><x-rupiah :nilai="$c['r']['omzet_bersih']" /></td>
                            <td class="px-4 py-2 text-right text-danger"><x-rupiah :nilai="$c['r']['beban']" /></td>
                            @if ($lihatLaba)
                                <td class="px-4 py-2 text-right"><x-rupiah :nilai="$c['r']['laba_bersih']" class="{{ $c['r']['laba_bersih'] >= 0 ? 'text-accent' : 'text-danger' }}" /></td>
                            @endif
                            <td class="px-4 py-2">
                                <div class="h-2 rounded-full bg-bg border border-line overflow-hidden">
                                    <div class="h-full bg-accent rounded-full" style="width: {{ round($c['r']['omzet_bersih'] / $maksOmzet * 100) }}%"></div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<div class="grid gap-4 lg:grid-cols-2">
    {{-- ================= Rincian keuangan ================= --}}
    <section class="kartu">
        <div class="px-4 py-3 border-b border-line flex justify-between items-center">
            <div class="font-semibold">Ringkasan keuangan</div>
            <span class="label">{{ $hari }} hari</span>
        </div>
        <dl class="px-4 py-3 text-sm space-y-2">
            <div class="flex justify-between"><dt class="text-muted">Sewa PS</dt><dd><x-rupiah :nilai="$r['pendapatan_sewa']" /></dd></div>
            <div class="flex justify-between"><dt class="text-muted">F&amp;B</dt><dd><x-rupiah :nilai="$r['pendapatan_fnb']" /></dd></div>
            @if ($r['pendapatan_lainnya'] > 0)
                <div class="flex justify-between"><dt class="text-muted">Lainnya</dt><dd><x-rupiah :nilai="$r['pendapatan_lainnya']" /></dd></div>
            @endif
            <div class="flex justify-between pt-2 border-t border-line"><dt>Omzet kotor</dt><dd><x-rupiah :nilai="$r['omzet_kotor']" /></dd></div>
            <div class="flex justify-between"><dt class="text-muted">Diskon / promo</dt><dd class="text-danger"><x-rupiah :nilai="-$r['diskon']" /></dd></div>
            <div class="flex justify-between font-semibold"><dt>Omzet bersih</dt><dd><x-rupiah :nilai="$r['omzet_bersih']" /></dd></div>

            @if ($lihatLaba)
                <div class="flex justify-between pt-2 border-t border-line"><dt class="text-muted">HPP F&amp;B</dt><dd class="text-danger"><x-rupiah :nilai="-$r['hpp']" /></dd></div>
                <div class="flex justify-between"><dt>Laba kotor</dt><dd><x-rupiah :nilai="$r['laba_kotor']" /></dd></div>
                <div class="flex justify-between"><dt class="text-muted">Pengeluaran operasional</dt><dd class="text-danger"><x-rupiah :nilai="-$r['beban']" /></dd></div>
                <div class="flex justify-between font-semibold text-base pt-2 border-t border-line">
                    <dt>Laba bersih</dt>
                    <dd><x-rupiah :nilai="$r['laba_bersih']" class="{{ $r['laba_bersih'] >= 0 ? 'text-accent' : 'text-danger' }}" /></dd>
                </div>
            @endif

            <div class="flex justify-between pt-2 border-t border-line text-muted">
                <dt>Transaksi dibatalkan</dt>
                <dd class="num">{{ $r['batal_jumlah'] }} · <x-rupiah :nilai="$r['batal_nilai']" /></dd>
            </div>
        </dl>
        @if ($lihatLaba)
            <p class="px-4 pb-3 text-xs text-muted">Belanja stok tidak dihitung sebagai beban karena sudah masuk lewat HPP saat barang terjual.</p>
        @endif
    </section>

    {{-- ================= Metode bayar ================= --}}
    <section class="kartu">
        <div class="px-4 py-3 border-b border-line font-semibold">Metode pembayaran</div>
        <div class="px-4 py-3 space-y-3">
            @forelse ($perMetode as $m)
                <div>
                    <div class="flex justify-between text-sm mb-1">
                        <span>{{ $metodeLabel[$m->metode] ?? $m->metode }} <span class="text-muted num">({{ $m->jumlah }})</span></span>
                        <x-rupiah :nilai="$m->total" />
                    </div>
                    <div class="h-2 rounded-full bg-bg border border-line overflow-hidden">
                        <div class="h-full bg-accent rounded-full" style="width: {{ round($m->total / $totalMetode * 100) }}%"></div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-muted">Belum ada pembayaran.</p>
            @endforelse
        </div>
    </section>

    {{-- ================= Jam sibuk ================= --}}
    <section class="kartu lg:col-span-2">
        <div class="px-4 py-3 border-b border-line flex justify-between items-center">
            <div class="font-semibold">Jam sibuk <span class="text-muted font-normal text-sm">(sesi dimulai)</span></div>
            @if (max($jamSibuk) > 0)
                <span class="label">Puncak {{ sprintf('%02d:00', $jamPuncak) }}</span>
            @endif
        </div>
        <div class="px-4 py-4">
            <div class="flex items-end gap-1 h-32">
                @foreach ($jamSibuk as $jam => $n)
                    <div class="flex-1 flex flex-col items-center justify-end h-full" title="{{ sprintf('%02d:00', $jam) }} · {{ $n }} sesi">
                        <div @class([
                                'w-full rounded-t',
                                'bg-accent' => $n > 0 && $n >= $maksJam * 0.75,
                                'bg-st-main' => $n > 0 && $n < $maksJam * 0.75,
                                'bg-line' => $n === 0,
                             ])
                             style="height: {{ $n > 0 ? max(6, round($n / $maksJam * 100)) : 3 }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="flex gap-1 mt-1.5">
                @foreach ($jamSibuk as $jam => $n)
                    <div class="flex-1 text-center label" style="font-size: 9px;">{{ $jam % 3 === 0 ? sprintf('%02d', $jam) : '' }}</div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ================= Per unit ================= --}}
    <section class="kartu">
        <div class="px-4 py-3 border-b border-line font-semibold">Unit</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-muted border-b border-line">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">Unit</th>
                        <th class="px-4 py-2 text-right font-medium">Sesi</th>
                        <th class="px-4 py-2 text-left font-medium w-1/3">Jam main</th>
                        <th class="px-4 py-2 text-right font-medium">Pendapatan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($perUnit as $u)
                        <tr>
                            <td class="px-4 py-2 font-medium">{{ $u['kode'] }}@if (! empty($perCabang) && count($perCabang) > 1) <span class="chip ml-1">{{ $u['cabang'] }}</span>@endif</td>
                            <td class="px-4 py-2 text-right num">{{ $u['sesi'] }}</td>
                            <td class="px-4 py-2">
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 flex-1 rounded-full bg-bg border border-line overflow-hidden">
                                        <div class="h-full bg-st-main" style="width: {{ round($u['detik'] / $maksUnit * 100) }}%"></div>
                                    </div>
                                    <span class="num text-xs text-muted w-14 text-right">{{ number_format($u['detik'] / 3600, 1, ',', '.') }} j</span>
                                </div>
                            </td>
                            <td class="px-4 py-2 text-right"><x-rupiah :nilai="$u['pendapatan']" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- ================= Produk terlaris ================= --}}
    <section class="kartu">
        <div class="px-4 py-3 border-b border-line font-semibold">Produk terlaris</div>
        @if ($produk->isEmpty())
            <p class="px-4 py-3 text-sm text-muted">Belum ada penjualan F&amp;B.</p>
        @else
            <ol class="divide-y divide-line text-sm">
                @foreach ($produk as $i => $p)
                    <li class="px-4 py-2 flex items-center justify-between gap-3">
                        <span class="min-w-0 flex items-center gap-2">
                            <span class="label w-5">{{ $i + 1 }}</span>
                            <span class="truncate">{{ $p->nama }}</span>
                        </span>
                        <span class="text-right shrink-0">
                            <span class="num">{{ $p->qty }}×</span>
                            <x-rupiah :nilai="$p->omzet" class="text-muted ml-2" />
                            @if ($lihatLaba)
                                <span class="block text-xs text-accent">laba <x-rupiah :nilai="$p->omzet - $p->hpp" /></span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    {{-- ================= Operator ================= --}}
    <section class="kartu">
        <div class="px-4 py-3 border-b border-line font-semibold">Kinerja kasir</div>
        @if ($operator->isEmpty())
            <p class="px-4 py-3 text-sm text-muted">Belum ada transaksi.</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-xs text-muted border-b border-line">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">Kasir</th>
                        <th class="px-4 py-2 text-right font-medium">Transaksi</th>
                        <th class="px-4 py-2 text-right font-medium">Omzet</th>
                        <th class="px-4 py-2 text-right font-medium">Batal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($operator as $o)
                        <tr>
                            <td class="px-4 py-2">{{ $o['nama'] }}</td>
                            <td class="px-4 py-2 text-right num">{{ $o['jumlah'] }}</td>
                            <td class="px-4 py-2 text-right"><x-rupiah :nilai="$o['omzet']" /></td>
                            <td class="px-4 py-2 text-right num {{ $o['batal'] > 0 ? 'text-danger' : 'text-muted' }}">{{ $o['batal'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    {{-- ================= Pengeluaran ================= --}}
    <section class="kartu">
        <div class="px-4 py-3 border-b border-line font-semibold">Pengeluaran per kategori</div>
        @if ($pengeluaran->isEmpty())
            <p class="px-4 py-3 text-sm text-muted">Tidak ada pengeluaran.</p>
        @else
            <ul class="divide-y divide-line text-sm">
                @foreach ($pengeluaran as $p)
                    <li class="px-4 py-2 flex justify-between gap-3">
                        <span>
                            {{ \App\Models\Pengeluaran::KATEGORI[$p->kategori] ?? $p->kategori }}
                            <span class="text-muted num">({{ $p->jumlah }})</span>
                        </span>
                        <x-rupiah :nilai="$p->total" class="text-danger" />
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- ================= Log anomali ================= --}}
    @isset($anomali)
        <section class="kartu lg:col-span-2">
            <div class="px-4 py-3 border-b border-line flex items-center justify-between gap-3">
                <span class="font-semibold">Log anomali</span>
                <span class="label">Void · gratis · bypass TV · PIN salah · selisih kas</span>
            </div>
            @if ($anomali['terbaru']->isEmpty())
                <p class="px-4 py-3 text-sm text-muted">Tidak ada kejadian mencurigakan di periode ini.</p>
            @else
                <div class="px-4 py-3 flex flex-wrap gap-2 border-b border-line">
                    @foreach ($anomali['per_aksi'] as $a)
                        <span class="chip" style="color: var(--status-hampir-habis)">
                            {{ \App\Models\AuditLog::LABEL_AKSI[$a->aksi] ?? $a->aksi }} · {{ $a->jumlah }}
                        </span>
                    @endforeach
                </div>
                <ul class="divide-y divide-line text-sm">
                    @foreach ($anomali['terbaru'] as $l)
                        <li class="px-4 py-2 flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block">{{ $l->keterangan }}</span>
                                <span class="block text-xs text-muted">{{ $l->labelAksi() }} · {{ $l->user?->name ?? 'Sistem' }}</span>
                            </span>
                            <span class="text-xs text-muted num shrink-0">{{ $l->created_at->timezone(config('app.timezone'))->format('d/m H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endisset
</div>
