<div class="max-w-lg mx-auto" data-rahasia>
    <div class="mb-4 flex items-start justify-between gap-3">
        <div>
            <div class="label">{{ $shift->diserahkan_ke ? 'Serah terima shift' : 'Tutup kas' }}</div>
            <h1 class="text-xl font-semibold num">{{ $shift->nomor }}</h1>
            <p class="text-sm text-muted">
                {{ $nama[$shift->user_id] ?? '-' }} · {{ $shift->dibuka_pada->format('d/m H:i') }} – {{ $shift->ditutup_pada?->format('H:i') ?? 'sekarang' }}
            </p>
        </div>
        <button type="button" onclick="window.print()" class="btn h-9 px-3 text-sm print:hidden">Cetak</button>
    </div>

    @include('livewire.operator.partials.ringkasan-kas', ['r' => $r])

    @if ($shift->ditutup_pada)
        <div class="surface mb-4">
            <div class="px-4 py-2.5 border-b border-line font-medium">Penutupan laci</div>
            <dl class="px-4 py-3 text-sm space-y-1.5">
                <div class="flex justify-between"><dt class="text-muted">Kas fisik dihitung</dt><dd><x-rupiah :nilai="$shift->kas_fisik" /></dd></div>
                <div class="flex justify-between"><dt class="text-muted">Selisih</dt>
                    <dd @class(['text-danger' => $shift->selisih < 0, 'text-st-kosong' => $shift->selisih > 0])><x-rupiah :nilai="$shift->selisih" /></dd></div>
                <div class="flex justify-between pt-1.5 border-t border-line"><dt class="text-muted">Ditinggal di laci (modal)</dt><dd><x-rupiah :nilai="(int) $shift->kas_ditinggal" /></dd></div>
                <div class="flex justify-between font-semibold"><dt>Disetor ke owner / brankas</dt><dd><x-rupiah :nilai="(int) $shift->setoran" /></dd></div>
                @if ($shift->catatan_tutup)
                    <div class="pt-1.5 border-t border-line text-muted">Catatan: {{ $shift->catatan_tutup }}</div>
                @endif
            </dl>
        </div>
    @endif

    @if ($shift->diserahkan_ke)
        <div class="surface mb-4">
            <div class="px-4 py-2.5 border-b border-line font-medium">Diserahkan ke {{ $nama[$shift->diserahkan_ke] ?? '-' }}</div>
            <dl class="px-4 py-3 text-sm space-y-1.5">
                <div class="flex justify-between"><dt class="text-muted">Shift penerima</dt><dd class="num">{{ $berikut?->nomor ?? '-' }}</dd></div>
                <div class="flex justify-between"><dt class="text-muted">Kas awal penerima</dt><dd><x-rupiah :nilai="(int) $berikut?->kas_awal" /></dd></div>
                @if ($berikut?->selisih_terima)
                    <div class="flex justify-between text-danger"><dt>Selisih saat diterima</dt><dd><x-rupiah :nilai="$berikut->selisih_terima" /></dd></div>
                @endif
            </dl>
        </div>
    @endif

    @if (! empty($potret['sesi_main']) || ! empty($potret['belum_bayar']))
        <div class="surface mb-4">
            <div class="px-4 py-2.5 border-b border-line font-medium">Diteruskan saat laci ditutup</div>
            <ul class="divide-y divide-line text-sm">
                @foreach ($potret['sesi_main'] as $s)
                    <li class="px-4 py-2 flex justify-between gap-3">
                        <span><span class="font-medium">{{ $s['unit'] }}</span> <span class="text-muted">· main · {{ $s['pelanggan'] ?: 'Tamu' }}</span></span>
                        @if (($s['sisa'] ?? 1) === 0 && $s['tagihan'] > 0)
                            <span class="text-xs text-st-kosong">lunas di muka</span>
                        @else
                            <x-rupiah :nilai="$s['tagihan']" />
                        @endif
                    </li>
                @endforeach
                @foreach ($potret['belum_bayar'] as $t)
                    <li class="px-4 py-2 flex justify-between gap-3">
                        <span><span class="font-medium">{{ $t['unit'] }}</span> <span class="text-danger">· belum bayar</span> <span class="text-muted num">{{ $t['nomor'] }}</span></span>
                        <x-rupiah :nilai="$t['sisa']" class="text-danger" />
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($perluKeluar)
        <form method="POST" action="{{ route('logout') }}" class="print:hidden">
            @csrf
            <button type="submit" class="btn btn-primary w-full h-11">Keluar & ganti kasir</button>
        </form>
        <p class="text-xs text-muted text-center mt-2 print:hidden">Laci sudah dipegang kasir berikutnya. Keluar supaya ia bisa login di perangkat ini.</p>
    @else
        <a href="{{ route('rental') }}" wire:navigate class="btn w-full h-11 print:hidden">Kembali ke Rental</a>
    @endif
</div>
