<div>
    <x-sheet wire:model="buka" :judul="$semua ? 'Pemberitahuan · semua TV' : 'Pemberitahuan · '.($this->unit?->nama ?? 'TV')">
        @php
            $kelasUkuran = ['sedang' => 'text-lg', 'besar' => 'text-2xl', 'jumbo' => 'text-3xl'][$ukuran] ?? 'text-2xl';
            $kelasHuruf = ['sans' => 'font-sans', 'serif' => 'font-serif', 'mono' => 'font-mono'][$huruf] ?? 'font-sans';
        @endphp

        {{-- Pratinjau seperti di tengah layar TV --}}
        <div class="rounded-md bg-bg border border-line p-4 mb-4">
            <div class="label mb-2">Pratinjau di TV · {{ \App\Support\PemberitahuanTv::DURASI[$detik] ?? $detik.' dtk' }}</div>
            <div class="mx-auto max-w-md rounded-lg border px-5 py-4 text-center"
                 style="border-color: var(--accent); background: color-mix(in srgb, var(--surface) 92%, transparent); box-shadow: 0 0 22px -8px var(--accent);">
                <div @class([$kelasUkuran, $kelasHuruf, 'leading-snug break-words whitespace-pre-line', 'font-bold' => $tebal, 'text-muted' => trim($teks) === ''])>
                    {{ trim($teks) !== '' ? $teks : 'Ketik atau pilih pesan…' }}
                </div>
            </div>
        </div>

        {{-- Pesan cepat --}}
        <div class="label mb-1.5">Pesan cepat</div>
        <div class="flex flex-wrap gap-1.5 mb-3">
            @foreach ($this->pesanCepat as $i => $p)
                <button type="button" wire:click="pilihPesan({{ $i }})"
                        @class(['btn h-8 px-3 text-xs', 'btn-primary' => $teks === $p])>{{ $p }}</button>
            @endforeach
        </div>

        {{-- Teks sendiri + emoji --}}
        <div class="label mb-1.5">Atau ketik sendiri</div>
        <textarea wire:model.live.debounce.300ms="teks" rows="2" maxlength="{{ \App\Support\PemberitahuanTv::MAKS_TEKS }}"
                  class="input h-auto py-2" placeholder="Contoh: Mohon tenang saat bermain"></textarea>
        <div class="flex items-center justify-between mt-1 mb-2">
            <span class="text-xs text-muted">Enter = baris baru</span>
            <span class="text-xs text-muted num">{{ mb_strlen($teks) }}/{{ \App\Support\PemberitahuanTv::MAKS_TEKS }}</span>
        </div>
        <div class="flex flex-wrap gap-1 mb-4">
            @foreach (\App\Support\PemberitahuanTv::EMOJI as $e)
                <button type="button" wire:click="tambahEmoji('{{ $e }}')" class="btn btn-ghost h-9 w-9 px-0 text-lg" title="Tambah {{ $e }}">{{ $e }}</button>
            @endforeach
        </div>

        {{-- Gaya --}}
        <div class="grid sm:grid-cols-2 gap-x-4 gap-y-3">
            <div>
                <div class="label mb-1.5">Lama tampil</div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach (\App\Support\PemberitahuanTv::DURASI as $d => $n)
                        <button type="button" wire:click="$set('detik', {{ $d }})"
                                @class(['btn h-8 px-3 text-xs num', 'btn-primary' => $detik === $d])>{{ $n }}</button>
                    @endforeach
                </div>
            </div>
            <div>
                <div class="label mb-1.5">Ukuran</div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach (\App\Support\PemberitahuanTv::UKURAN as $k => $n)
                        <button type="button" wire:click="$set('ukuran', '{{ $k }}')"
                                @class(['btn h-8 px-3 text-xs', 'btn-primary' => $ukuran === $k])>{{ $n }}</button>
                    @endforeach
                </div>
            </div>
            <div>
                <div class="label mb-1.5">Jenis huruf</div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach (\App\Support\PemberitahuanTv::HURUF as $k => $n)
                        <button type="button" wire:click="$set('huruf', '{{ $k }}')"
                                @class([
                                    'btn h-8 px-3 text-xs',
                                    ['sans' => 'font-sans', 'serif' => 'font-serif', 'mono' => 'font-mono'][$k],
                                    'btn-primary' => $huruf === $k,
                                ])>{{ $n }}</button>
                    @endforeach
                </div>
            </div>
            <div>
                <div class="label mb-1.5">Tebal</div>
                <button type="button" wire:click="$toggle('tebal')"
                        @class(['btn h-8 px-3 text-xs font-bold', 'btn-primary' => $tebal])>B · {{ $tebal ? 'Tebal' : 'Biasa' }}</button>
            </div>
        </div>

        <label class="flex items-center gap-2 mt-4 text-sm">
            <input type="checkbox" wire:model.live="semua" class="size-4 accent-[var(--accent)]">
            Kirim ke semua TV di cabang ini
        </label>

        <x-slot:footer>
            <button type="button" wire:click="kirim" wire:loading.attr="disabled" wire:target="kirim"
                    class="btn btn-primary w-full h-11" @disabled(trim($teks) === '')>
                <x-ikon name="pengumuman" size="16" /> Tampilkan di {{ $semua ? 'semua TV' : 'TV' }}
            </button>
            <p class="text-xs text-muted mt-2 text-center">Muncul di tengah layar TV lalu hilang sendiri. TV yang offline &gt; 2 menit tidak menerima.</p>
        </x-slot:footer>
    </x-sheet>
</div>
