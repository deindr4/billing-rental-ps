{{--
    Bagan / klasemen turnamen semua format. Dipakai halaman kasir ($ubah = true: input skor, unit, tombol)
    dan halaman publik ($ubah = false).
    Variabel: $bagian (TurnamenService::tampilan), $ubah (bool), $units (Collection, hanya kasir)
--}}
@foreach ($bagian as $bg)
    <section class="space-y-2">
        <div class="font-semibold">{{ $bg['judul'] }}</div>

        @if ($bg['jenis'] === 'klasemen')
            <div class="kartu overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted text-xs">
                            <th class="text-left px-3 py-2 w-8">#</th>
                            <th class="text-left px-2 py-2">Peserta</th>
                            <th class="px-2 py-2 num" title="Main">M</th>
                            <th class="px-2 py-2 num" title="Menang">W</th>
                            <th class="px-2 py-2 num" title="Seri">D</th>
                            <th class="px-2 py-2 num" title="Kalah">L</th>
                            <th class="px-2 py-2 num" title="Selisih gol">SG</th>
                            <th class="px-3 py-2 num" title="Poin">Poin</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bg['klasemen'] as $i => $r)
                            <tr class="border-t border-line {{ $bg['lolos'] && $i < $bg['lolos'] ? 'font-semibold' : '' }}"
                                @if ($bg['lolos'] && $i < $bg['lolos']) style="background: color-mix(in srgb, var(--accent) 10%, transparent)" @endif>
                                <td class="px-3 py-1.5 num text-muted">{{ $i + 1 }}</td>
                                <td class="px-2 py-1.5 truncate max-w-40">{{ $r['peserta']->nama }}</td>
                                <td class="px-2 py-1.5 num text-center">{{ $r['main'] }}</td>
                                <td class="px-2 py-1.5 num text-center">{{ $r['menang'] }}</td>
                                <td class="px-2 py-1.5 num text-center">{{ $r['seri'] }}</td>
                                <td class="px-2 py-1.5 num text-center">{{ $r['kalah'] }}</td>
                                <td class="px-2 py-1.5 num text-center">{{ $r['sg'] > 0 ? '+' : '' }}{{ $r['sg'] }}</td>
                                <td class="px-3 py-1.5 num text-center font-bold">{{ $r['poin'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($bg['lolos'])
                    <div class="px-3 py-1.5 text-xs text-muted border-t border-line">{{ $bg['lolos'] }} teratas lolos ke babak gugur</div>
                @endif
            </div>
        @endif

        <div class="flex gap-3 overflow-x-auto pb-2">
            @foreach ($bg['kolom'] as $kol)
                <div @class(['flex-1 flex flex-col gap-2', 'min-w-64' => $ubah, 'min-w-44' => ! $ubah])>
                    <div class="label text-center">{{ $kol['nama'] }}</div>
                    <div class="flex-1 flex flex-col justify-around gap-2">
                        @foreach ($kol['laga'] as $m)
                            @php $siap = $m->peserta_a_id && $m->peserta_b_id && $m->status !== 'selesai'; @endphp
                            <div wire:key="m-{{ $m->id }}" class="kartu {{ $ubah ? 'p-3 space-y-2' : 'p-2' }} text-sm" @if ($m->status === 'main') style="border-color: var(--accent)" @endif>
                                @foreach ([['pesertaA', 'skor_a', 'a'], ['pesertaB', 'skor_b', 'b']] as [$rel, $kol2, $sisi])
                                    @php $pp = $m->$rel; $menang = $m->pemenang_id && $pp && $m->pemenang_id === $pp->id; @endphp
                                    <div class="flex items-center justify-between gap-2 {{ $menang ? 'font-bold text-accent' : ($m->pemenang_id ? 'text-muted' : '') }}">
                                        <span class="truncate">{{ $pp?->nama ?? ($m->status === 'selesai' ? 'BYE' : 'TBD') }}</span>
                                        @if ($ubah && $siap)
                                            <input type="number" min="0" wire:model="skor.{{ $m->id }}.{{ $sisi }}" class="input h-8 w-16 num text-sm">
                                        @else
                                            <span class="num">{{ $m->$kol2 }}</span>
                                        @endif
                                    </div>
                                @endforeach

                                @if ($ubah && $siap)
                                    <div class="flex items-center gap-1.5 pt-1">
                                        <select wire:model="unitMain.{{ $m->id }}" class="input h-8 py-0 text-xs flex-1">
                                            <option value="">{{ $m->unit?->nama ?? 'Pilih unit' }}</option>
                                            @foreach ($units as $u) <option value="{{ $u->id }}">{{ $u->nama }}</option> @endforeach
                                        </select>
                                        @if ($m->status === 'menunggu')
                                            <button type="button" wire:click="main('{{ $m->id }}')" class="btn h-8 px-2 text-xs">Main</button>
                                        @endif
                                        <button type="button" wire:click="simpanSkor('{{ $m->id }}')" class="btn btn-primary h-8 px-2 text-xs">Skor</button>
                                    </div>
                                @elseif ($m->unit && $m->status !== 'selesai')
                                    <div class="label">{{ $m->unit->nama }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endforeach
