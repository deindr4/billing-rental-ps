<div>
    <x-sheet wire:model="buka" :judul="$this->unit ? 'TV · '.$this->unit->nama : 'TV'">
        @if ($this->unit)
            @php $tv = $this->perangkat; @endphp

            @if (! $tv)
                <div class="rounded-md border border-dashed border-line px-3 py-6 text-center text-sm">
                    <div class="font-medium">Belum ada TV di unit ini</div>
                    <div class="text-muted mt-1">Minta admin memasangkan TV di Admin → Rental → Perangkat TV.</div>
                </div>
            @else
                @php
                    $online = $tv->isOnline();
                    $layar = [
                        'kunci' => 'Terkunci', 'main' => 'Main', 'jeda' => 'Dijeda', 'habis' => 'Waktu habis',
                        'menunggu_bayar' => 'Menunggu bayar', 'servis' => 'Maintenance', 'bypass' => 'Bypass',
                        'belum_ada_unit' => 'Belum ada unit',
                    ][$tv->layar] ?? '-';
                @endphp

                {{-- Status --}}
                <dl class="rounded-md border border-line divide-y divide-line text-sm mb-4">
                    <div class="px-3 py-2 flex justify-between gap-3">
                        <dt class="text-muted">Koneksi</dt>
                        <dd class="flex items-center gap-1.5 font-medium" style="color: {{ $online ? 'var(--status-kosong)' : 'var(--status-offline)' }};">
                            <span class="dot"></span>{{ $online ? 'Online' : 'Offline' }}
                        </dd>
                    </div>
                    <div class="px-3 py-2 flex justify-between gap-3">
                        <dt class="text-muted">Terakhir terlihat</dt>
                        <dd>{{ $tv->terakhir_online?->diffForHumans() ?? 'Belum pernah' }}</dd>
                    </div>
                    <div class="px-3 py-2 flex justify-between gap-3">
                        <dt class="text-muted">Tampilan TV</dt>
                        <dd>{{ $layar }}</dd>
                    </div>
                    @if ($tv->sedangBypass())
                        <div class="px-3 py-2 flex justify-between gap-3" style="color: var(--status-hampir-habis);">
                            <dt>Bypass</dt>
                            <dd class="font-medium">sampai {{ $tv->bypass_sampai->format('H:i') }}</dd>
                        </div>
                    @endif
                    <div class="px-3 py-2 flex justify-between gap-3">
                        <dt class="text-muted">Perangkat</dt>
                        <dd class="text-right truncate">{{ $tv->namaTampil() }}{{ $tv->versi_app ? ' · v'.$tv->versi_app : '' }}</dd>
                    </div>
                </dl>

                {{-- Kode darurat --}}
                @if ($kodeDarurat)
                    <div class="rounded-md border border-accent px-3 py-4 mb-4 text-center"
                         x-data="{ sisa: 0, init() { const f = () => this.sisa = Math.max(0, Math.round(({{ $kodeBerlakuSampai }} - (window.jamServer ? window.jamServer.sekarang() : Date.now())) / 1000)); f(); setInterval(f, 1000); } }">
                        <div class="label">Kode darurat · ketik di TV</div>
                        <div class="num text-4xl font-semibold tracking-[0.3em] mt-2">{{ $kodeDarurat }}</div>
                        <div class="text-xs text-muted mt-2">
                            <span x-show="sisa > 0">Berganti dalam <span x-text="Math.floor(sisa / 60) + ':' + String(sisa % 60).padStart(2, '0')"></span></span>
                            <span x-show="sisa === 0" x-cloak class="text-danger">Kode sudah berganti, lihat ulang</span>
                        </div>
                        <button type="button" wire:click="tutupKode" class="btn btn-ghost h-8 text-sm mt-2">Sembunyikan</button>
                    </div>
                @endif

                {{-- Riwayat --}}
                @if ($this->riwayat->isNotEmpty())
                    <div class="text-sm font-medium mb-1.5">Riwayat</div>
                    <ul class="rounded-md border border-line divide-y divide-line text-sm">
                        @foreach ($this->riwayat as $log)
                            <li class="px-3 py-2">
                                <div>{{ \App\Models\LogTv::LABEL[$log->jenis] ?? $log->jenis }}{{ ! empty($log->data['menit']) ? ' · '.$log->data['menit'].' menit' : '' }}</div>
                                <div class="text-xs text-muted">
                                    {{ $log->created_at->format('d/m H:i') }} · {{ $log->user?->name ?? 'Sistem' }}
                                    @if (! empty($log->data['pemohon']))
                                        · diminta {{ $log->data['pemohon'] }}
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <x-slot:footer>
                    {{-- Durasi bypass / perpanjangan --}}
                    <div class="flex items-center gap-2 mb-2">
                        <span class="label shrink-0">{{ $tv->sedangBypass() ? 'Tambah' : 'Durasi' }}</span>
                        <div class="flex gap-1.5 overflow-x-auto">
                            @foreach ($this->pilihanMenit as $m)
                                <button type="button" wire:click="pilihMenit({{ $m }})"
                                        @class(['btn h-8 px-3 text-xs num shrink-0', 'btn-primary' => $menitBypass === $m])>
                                    {{ $m >= 60 && $m % 60 === 0 ? ($m / 60).' jam' : $m.' mnt' }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        @if ($tv->sedangBypass())
                            <x-confirm-button action="perpanjangBypass"
                                              title="Perpanjang bypass {{ $menitBypass }} menit?"
                                              text="Butuh PIN supervisor/owner."
                                              confirm-text="Perpanjang"
                                              pin
                                              class="w-full">
                                Perpanjang
                            </x-confirm-button>
                            <button type="button" wire:click="akhiriBypass" class="btn">Akhiri bypass</button>
                        @else
                            <x-confirm-button action="bypass"
                                              title="Buka TV {{ $menitBypass }} menit?"
                                              text="TV terbuka tanpa sesi (nonton YouTube, tes, servis). Di TV bisa dipilih: PS, YouTube, atau aplikasi lain yang diizinkan. Butuh PIN supervisor/owner."
                                              confirm-text="Buka TV"
                                              pin
                                              class="w-full">
                                Bypass
                            </x-confirm-button>
                        @endif

                        <x-confirm-button action="lihatKodeDarurat"
                                          title="Lihat kode darurat?"
                                          text="Untuk membuka TV saat jaringan putus. Butuh PIN supervisor/owner."
                                          confirm-text="Tampilkan"
                                          pin
                                          @class(['w-full', 'col-span-2' => $tv->sedangBypass()])>
                            Kode darurat
                        </x-confirm-button>
                    </div>
                </x-slot:footer>
            @endif
        @endif
    </x-sheet>
</div>
