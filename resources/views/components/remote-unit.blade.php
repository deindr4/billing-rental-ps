{{--
    Tombol remote TV / PC satu unit (dipakai kartu unit & tampilan daftar Rental).
    bingkai = kelas pembungkus (kartu: garis atas; daftar: tanpa garis, rata kanan).
--}}
@props(['unit', 'tv' => null, 'bisaRemote' => false, 'bingkai' => 'px-3 py-2.5 border-t border-line justify-center'])

{{-- Remote PC (agen kiosk Windows): mati → Nyalakan (Wake-on-LAN); menyala → kunci, tutup game, Task Manager, daya --}}
@if ($tv && $tv->isPc() && ! $tv->isOnline())
    <div class="{{ $bingkai }} flex items-center gap-2">
        <span class="text-xs text-muted">{{ __('PC mati / offline') }}</span>
        @if ($bisaRemote)
            <button type="button" title="{{ __('Nyalakan PC (Wake-on-LAN)') }}" wire:click="nyalakanPc('{{ $unit->id }}')"
                    class="btn btn-remote text-st-kosong">
                <x-ikon name="daya" size="16" />
            </button>
        @endif
    </div>
@elseif ($tv && $tv->isPc())
    <div class="{{ $bingkai }} flex items-center gap-1.5 min-[380px]:gap-2">
        <span title="{{ __('Tutup game yang hang') }}">
            <x-confirm-button action="perintahTv" :params="[$unit->id, 'tutup_game']"
                              :title="__('Tutup game di :unit?', ['unit' => $unit->nama])"
                              :text="__('Aplikasi yang sedang di depan dipaksa tutup, pemain kembali ke kiosk. Waktu sewa tetap berjalan.')"
                              :confirm-text="__('Tutup game')"
                              class="btn-remote text-danger">
                <x-ikon name="tutup-game" size="16" />
            </x-confirm-button>
        </span>
        @if ($bisaRemote)
            <span title="{{ __('Izinkan Task Manager sementara') }}">
                <x-confirm-button action="izinTaskManager" :params="[$unit->id]"
                                  :title="__('Buka Task Manager di :unit?', ['unit' => $unit->nama])"
                                  :text="__('Task Manager boleh dibuka sementara (lihat Admin → Pengaturan Operasional → Rental PC), lalu diblok lagi otomatis.')"
                                  :confirm-text="__('Izinkan')"
                                  class="btn-remote text-ik-kuning">
                    <x-ikon name="aktivitas" size="16" />
                </x-confirm-button>
            </span>
        @endif

        <button type="button" title="{{ __('Pemberitahuan ke layar PC') }}"
                wire:click="$dispatch('buka-pemberitahuan', { unitId: '{{ $unit->id }}' })"
                class="btn btn-remote text-ik-pink ml-1 min-[380px]:ml-2">
            <x-ikon name="pengumuman" size="16" />
        </button>
        <button type="button" title="{{ __('Bypass (pilih durasi & PIN)') }}"
                wire:click="$dispatch('buka-kelola-tv', { unitId: '{{ $unit->id }}' })"
                @class(['btn btn-remote', 'text-st-main bg-st-main/15' => $tv->sedangBypass(), 'text-ik-ungu' => ! $tv->sedangBypass()])>
            <x-ikon name="gembok-buka" size="16" />
        </button>

        @if ($bisaRemote)
            <span title="{{ __('Kunci PC') }}" class="ml-1 min-[380px]:ml-2">
                <x-confirm-button action="perintahTv" :params="[$unit->id, 'kunci']"
                                  :title="__('Kunci :unit?', ['unit' => $unit->nama])" :text="__('Layar kiosk PC dikunci lagi.')" :confirm-text="__('Kunci')"
                                  class="btn-remote text-ik-biru">
                    <x-ikon name="gembok" size="16" />
                </x-confirm-button>
            </span>
            <span title="{{ __('Log off akun pemain') }}">
                <x-confirm-button action="perintahTv" :params="[$unit->id, 'logoff_pc']"
                                  :title="__('Log off akun pemain di :unit?', ['unit' => $unit->nama])" :text="__('Semua aplikasi pemain ditutup, Windows kembali ke layar login lalu kiosk.')" :confirm-text="__('Log off')"
                                  class="btn-remote text-ik-ungu">
                    <x-ikon name="keluar" size="16" />
                </x-confirm-button>
            </span>
            <span title="{{ __('Restart PC') }}">
                <x-confirm-button action="perintahTv" :params="[$unit->id, 'restart_pc']"
                                  :title="__('Restart :unit?', ['unit' => $unit->nama])" :text="__('PC dinyalakan ulang (±1–2 menit).')" :confirm-text="__('Restart')"
                                  class="btn-remote text-ik-teal">
                    <x-ikon name="restart" size="16" />
                </x-confirm-button>
            </span>
            <span title="{{ __('Matikan PC') }}">
                <x-confirm-button action="perintahTv" :params="[$unit->id, 'matikan_pc']"
                                  :title="__('Matikan :unit?', ['unit' => $unit->nama])" :text="__('PC dimatikan. Nyalakan lagi dari tombol daya di kartu ini (Wake-on-LAN) atau manual.')" :confirm-text="__('Matikan')"
                                  class="btn-remote text-ik-oranye">
                    <x-ikon name="daya" size="16" />
                </x-confirm-button>
            </span>
        @endif
    </div>

{{-- Remote TV. TV standby berhenti melapor (tampak offline): tombol Bangunkan tetap ada --}}
@elseif ($tv && ! $tv->isOnline())
    <div class="{{ $bingkai }} flex items-center gap-2">
        <span class="text-xs text-muted">{{ __('TV offline / standby') }}</span>
        @if ($bisaRemote)
            <button type="button" title="{{ __('Bangunkan TV') }}"
                    wire:click="perintahTv('{{ $unit->id }}', 'layar_nyala')"
                    class="btn btn-remote text-st-kosong">
                <x-ikon name="bangun" size="16" />
            </button>
        @endif
    </div>
@elseif ($tv)
    @php
        $layarMati = $tv->layar_hidup === false;
        $bisaReboot = (bool) ($tv->diagnostik['device_owner'] ?? false);
    @endphp
    {{-- Dikelompokkan: daya | volume | pemberitahuan, bypass & restart (jarak antar kelompok lebih lebar) --}}
    <div class="{{ $bingkai }} flex items-center gap-1.5 min-[380px]:gap-2">
        @if ($bisaRemote)
            {{-- Dua tombol terpisah (bukan satu tombol yang berganti) supaya jelas: matahari = bangunkan, daya = matikan --}}
            <button type="button" title="{{ __('Bangunkan TV') }}"
                    wire:click="perintahTv('{{ $unit->id }}', 'layar_nyala')"
                    @class(['btn btn-remote', 'text-st-kosong bg-st-kosong/15' => $layarMati, 'text-st-kosong' => ! $layarMati])>
                <x-ikon name="bangun" size="16" />
            </button>
            <x-confirm-button action="perintahTv" :params="[$unit->id, 'layar_mati']"
                              :title="__('Matikan layar :unit?', ['unit' => $unit->nama])"
                              :text="__('TV masuk mode standby. Bangunkan lagi dari tombol matahari atau remote TV.')"
                              :confirm-text="__('Matikan')"
                              class="btn-remote text-ik-oranye">
                <x-ikon name="daya" size="16" />
            </x-confirm-button>
        @endif

        <button type="button" title="{{ __('Volume turun') }}" wire:click="perintahTv('{{ $unit->id }}', 'volume_turun')"
                @class(['btn btn-remote text-ik-biru', 'ml-1 min-[380px]:ml-2' => $bisaRemote])>
            <x-ikon name="vol-turun" size="16" />
        </button>
        <span class="num text-xs w-8 text-center {{ $tv->senyap ? 'text-danger' : 'text-muted' }}" title="{{ __('Volume TV') }}">
            {{ $tv->senyap ? 'MUTE' : ($tv->volume !== null ? $tv->volume.'%' : '–') }}
        </span>
        <button type="button" title="{{ __('Volume naik') }}" wire:click="perintahTv('{{ $unit->id }}', 'volume_naik')"
                class="btn btn-remote text-ik-biru">
            <x-ikon name="vol-naik" size="16" />
        </button>
        <button type="button" title="{{ __('Senyap / bunyikan') }}" wire:click="perintahTv('{{ $unit->id }}', 'volume_senyap')"
                @class(['btn btn-remote', 'text-danger bg-danger/15' => $tv->senyap, 'text-ik-kuning' => ! $tv->senyap])>
            <x-ikon name="senyap" size="16" />
        </button>

        <button type="button" title="{{ __('Pemberitahuan ke layar TV') }}"
                wire:click="$dispatch('buka-pemberitahuan', { unitId: '{{ $unit->id }}' })"
                class="btn btn-remote text-ik-pink ml-1 min-[380px]:ml-2">
            <x-ikon name="pengumuman" size="16" />
        </button>

        <button type="button" title="{{ __('Bypass (pilih durasi & PIN)') }}"
                wire:click="$dispatch('buka-kelola-tv', { unitId: '{{ $unit->id }}' })"
                @class(['btn btn-remote', 'text-st-main bg-st-main/15' => $tv->sedangBypass(), 'text-ik-ungu' => ! $tv->sedangBypass()])>
            <x-ikon name="gembok-buka" size="16" />
        </button>

        @if ($bisaRemote)
            <x-confirm-button action="perintahTv" :params="[$unit->id, 'restart_tv']"
                              :title="__('Restart :unit?', ['unit' => $unit->nama])"
                              :text="$bisaReboot ? __('TV akan dinyalakan ulang (±1 menit).') : __('TV ini tidak mengizinkan restart penuh; aplikasi TV Agent yang akan dimulai ulang.')"
                              :confirm-text="__('Restart')"
                              class="btn-remote text-ik-teal">
                <x-ikon name="restart" size="16" />
            </x-confirm-button>
        @endif
    </div>
@endif
