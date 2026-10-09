<div>
    <x-sheet wire:model="buka" :judul="$this->unit ? __('TV · :unit', ['unit' => $this->unit->nama]) : __('TV')">
        @if ($this->unit)
            @php $tv = $this->perangkat; @endphp

            @if (! $tv)
                <div class="rounded-md border border-dashed border-line px-3 py-6 text-center text-sm">
                    <div class="font-medium">{{ __('Belum ada TV di unit ini') }}</div>
                    <div class="text-muted mt-1">{{ __('Minta admin memasangkan TV di Admin → Rental → Perangkat TV.') }}</div>
                </div>
            @else
                @php
                    $online = $tv->isOnline();
                    $layar = [
                        'kunci' => __('Terkunci'), 'main' => __('Main'), 'jeda' => __('Dijeda'), 'habis' => __('Waktu habis'),
                        'menunggu_bayar' => __('Menunggu bayar'), 'servis' => __('Maintenance'), 'bypass' => __('Unlock'),
                        'tutup' => __('Aplikasi ditutup (TV bebas)'), 'darurat' => __('Buka darurat'),
                        'belum_ada_unit' => __('Belum ada unit'),
                    ][$tv->layar] ?? '-';
                    $lama = fn (int $m) => $m >= 60 && $m % 60 === 0 ? __(':n jam', ['n' => $m / 60]) : __(':n mnt', ['n' => $m]);
                @endphp

                {{-- Status --}}
                <dl class="rounded-md border border-line divide-y divide-line text-sm mb-4">
                    <div class="px-3 py-2 flex justify-between gap-3">
                        <dt class="text-muted">{{ __('Koneksi') }}</dt>
                        <dd class="flex items-center gap-1.5 font-medium" style="color: {{ $online ? 'var(--status-kosong)' : 'var(--status-offline)' }};">
                            <span class="dot"></span>{{ $online ? __('Online') : __('Offline') }}
                        </dd>
                    </div>
                    <div class="px-3 py-2 flex justify-between gap-3">
                        <dt class="text-muted">{{ __('Terakhir terlihat') }}</dt>
                        <dd>{{ $tv->terakhir_online?->diffForHumans() ?? __('Belum pernah') }}</dd>
                    </div>
                    <div class="px-3 py-2 flex justify-between gap-3">
                        <dt class="text-muted">{{ __('Tampilan TV') }}</dt>
                        <dd>{{ $layar }}</dd>
                    </div>
                    @if ($tv->sedangBypass())
                        <div class="px-3 py-2 flex justify-between gap-3" style="color: var(--status-hampir-habis);">
                            <dt>{{ __('Unlock') }}</dt>
                            <dd class="font-medium">{{ __('sampai :jam', ['jam' => $tv->bypass_sampai->format('H:i')]) }}</dd>
                        </div>
                    @endif
                    <div class="px-3 py-2 flex justify-between gap-3">
                        <dt class="text-muted">{{ __('Perangkat') }}</dt>
                        <dd class="text-right truncate">{{ $tv->namaTampil() }}{{ $tv->versi_app ? ' · v'.$tv->versi_app : '' }}</dd>
                    </div>
                </dl>

                {{-- Kode darurat --}}
                @if ($kodeDarurat)
                    <div class="rounded-md border border-accent px-3 py-4 mb-4 text-center"
                         x-data="{ sisa: 0, init() { const f = () => this.sisa = Math.max(0, Math.round(({{ $kodeBerlakuSampai }} - (window.jamServer ? window.jamServer.sekarang() : Date.now())) / 1000)); f(); setInterval(f, 1000); } }">
                        <div class="label">{{ __('Kode darurat · ketik di TV') }}</div>
                        <div class="num text-4xl font-semibold tracking-[0.3em] mt-2">{{ $kodeDarurat }}</div>
                        <div class="text-xs text-muted mt-2">
                            <span x-show="sisa > 0">{{ __('Berganti dalam') }} <span x-text="Math.floor(sisa / 60) + ':' + String(sisa % 60).padStart(2, '0')"></span></span>
                            <span x-show="sisa === 0" x-cloak class="text-danger">{{ __('Kode sudah berganti, lihat ulang') }}</span>
                        </div>
                        <button type="button" wire:click="tutupKode" class="btn btn-ghost h-8 text-sm mt-2">{{ __('Sembunyikan') }}</button>
                    </div>
                @endif

                {{-- Riwayat --}}
                @if ($this->riwayat->isNotEmpty())
                    <div class="text-sm font-medium mb-1.5">{{ __('Riwayat') }}</div>
                    <ul class="rounded-md border border-line divide-y divide-line text-sm">
                        @foreach ($this->riwayat as $log)
                            <li class="px-3 py-2">
                                <div>{{ __(\App\Models\LogTv::LABEL[$log->jenis] ?? $log->jenis) }}{{ ! empty($log->data['menit']) ? ' · '.__(':n menit', ['n' => $log->data['menit']]) : '' }}</div>
                                <div class="text-xs text-muted">
                                    {{ $log->created_at->format('d/m H:i') }} · {{ $log->user?->name ?? __('Sistem') }}
                                    @if (! empty($log->data['pemohon']))
                                        · {{ __('diminta :nama', ['nama' => $log->data['pemohon']]) }}
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <x-slot:footer>
                    {{-- Durasi bypass / perpanjangan --}}
                    <div class="flex items-center gap-2 mb-2">
                        <span class="label shrink-0">{{ $tv->sedangBypass() ? __('Tambah') : __('Unlock') }}</span>
                        <div class="flex gap-1.5 overflow-x-auto">
                            @foreach ($this->pilihanMenit as $m)
                                <button type="button" wire:click="pilihMenit({{ $m }})"
                                        @class(['btn h-8 px-3 text-xs num shrink-0', 'btn-primary' => $menitBypass === $m])>
                                    {{ $lama($m) }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        @if ($tv->sedangBypass())
                            <x-confirm-button action="perpanjangBypass"
                                              :title="__('Perpanjang unlock :n menit?', ['n' => $menitBypass])"
                                              :text="__('Butuh PIN supervisor/owner.')"
                                              :confirm-text="__('Perpanjang')"
                                              pin
                                              class="btn-tint tint-hijau w-full">
                                {{ __('Perpanjang') }}
                            </x-confirm-button>
                        @else
                            <x-confirm-button action="bypass"
                                              :title="__('Unlock TV :n menit?', ['n' => $menitBypass])"
                                              :text="__('TV terbuka tanpa sesi (Google TV, YouTube, HDMI, tes, servis), lalu terkunci otomatis setelah waktunya habis. Butuh PIN supervisor/owner.')"
                                              :confirm-text="__('Unlock')"
                                              pin
                                              class="btn-tint tint-hijau w-full">
                                <x-ikon name="gembok-buka" size="16" /> {{ __('Unlock') }}
                            </x-confirm-button>
                        @endif

                        {{-- Lock: akhiri unlock & paksa aplikasi TV tampil + terkunci lagi (juga setelah aplikasi ditutup) --}}
                        <button type="button" wire:click="kunci" wire:loading.attr="disabled" class="btn btn-tint tint-oranye">{{ __('Lock') }}</button>

                        <x-confirm-button action="tutupAplikasi"
                                          :title="__('Tutup aplikasi TV?')"
                                          :text="__('TV Agent berhenti menjaga layar: TV bebas dipakai (layar Google TV) sampai Anda menekan Lock atau sesi berikutnya dimulai. Butuh PIN supervisor/owner.')"
                                          :confirm-text="__('Tutup aplikasi')"
                                          pin
                                          class="btn-tint tint-merah w-full">
                            <x-ikon name="tutup" size="16" /> {{ __('Tutup aplikasi') }}
                        </x-confirm-button>

                        <x-confirm-button action="lihatKodeDarurat"
                                          :title="__('Lihat kode darurat?')"
                                          :text="__('Untuk membuka TV saat jaringan putus. Butuh PIN supervisor/owner.')"
                                          :confirm-text="__('Tampilkan')"
                                          pin
                                          class="btn-tint tint-kuning w-full">
                            {{ __('Kode darurat') }}
                        </x-confirm-button>
                    </div>
                    <p class="text-xs text-muted mt-2">{{ __('Di TV: tekan Home lalu OK untuk akses staf (PIN).') }}</p>
                </x-slot:footer>
            @endif
        @endif
    </x-sheet>
</div>
