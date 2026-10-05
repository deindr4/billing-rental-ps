@php
    $tenancy = app(\App\Support\Tenancy::class);
    $user = auth()->user();
    $tenant = $user?->tenant;
    $cabangAktif = $tenancy->cabangId() ? \App\Models\Cabang::find($tenancy->cabangId()) : null;
    $shiftAktif = ($user && $cabangAktif) ? app(\App\Services\Billing\ShiftService::class)->aktif($user, $cabangAktif->id) : null;
    $bisaGantiCabang = $user && $user->cabangTersedia()->count() > 1;
    $bisaAdmin = (bool) $user?->bisaBukaAdmin();

    $temaMode = \App\Support\Tema::mode();
    $temaAksen = \App\Support\Tema::aksen();
    $temaKontras = \App\Support\Tema::aksenKontras();
    $logoUrl = \App\Support\Tema::logoUrl();

    $zona = ['Asia/Jakarta' => 'WIB', 'Asia/Makassar' => 'WITA', 'Asia/Jayapura' => 'WIT'][$cabangAktif?->zona_waktu ?? config('app.timezone')] ?? '';

    $menu = \App\Support\MenuOperator::tersedia();
    $menuBawah = \App\Support\MenuOperator::bawah();
    $inisial = collect(explode(' ', $user?->name ?? '?'))->map(fn ($k) => mb_substr($k, 0, 1))->take(2)->implode('');
@endphp
<!DOCTYPE html>
<html lang="id" data-theme="{{ $temaMode }}" style="--accent: {{ $temaAksen }}; --accent-contrast: {{ $temaKontras }};">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Rental' }} · {{ config('app.name') }}</title>
    <x-ikon-aplikasi />
    @include('partials.sembunyi-uang')

    @vite(['resources/css/operator.css', 'resources/js/operator.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-bg text-fg"
      x-data="{ lainnya: false }"
      @if (session('ui')) data-flash='@json(session('ui'))' @endif>

<div class="lg:flex min-h-screen"
     x-data="{
         ciut: localStorage.getItem('sidebar-ciut') === '1',
         ubahCiut() {
             this.ciut = ! this.ciut;
             localStorage.setItem('sidebar-ciut', this.ciut ? '1' : '0');
         },
     }">

    {{-- ===================== SIDEBAR (desktop) ===================== --}}
    <aside class="hidden lg:flex flex-col shrink-0 sticky top-0 h-screen border-r border-line bg-surface transition-[width] duration-200"
           :class="ciut ? 'w-[68px]' : 'w-60'">

        {{-- Brand --}}
        <div class="h-16 px-3 flex items-center gap-3 border-b border-line">
            <img src="{{ $logoUrl ?: \App\Support\Tema::logoBawaan() }}" alt="Logo" class="h-9 w-9 shrink-0 rounded-md object-contain">
            <div class="min-w-0" x-show="! ciut">
                <div class="font-semibold leading-tight truncate">{{ $tenant?->nama ?? config('app.name') }}</div>
                <div class="label truncate">{{ $cabangAktif?->nama ?? '-' }}</div>
            </div>
        </div>

        {{-- Menu --}}
        <nav class="flex-1 overflow-y-auto px-2 py-3">
            @foreach ($menu as $grup => $items)
                <div class="label px-3 mt-3 mb-1.5 first:mt-0" x-show="! ciut">{{ $grup }}</div>
                <div class="h-px bg-line mx-2 my-3" x-show="ciut" x-cloak></div>

                @foreach ($items as $item)
                    <a href="{{ route($item['route']) }}" wire:navigate title="{{ $item['label'] }}"
                       @class([
                           'relative flex items-center gap-3 h-10 px-3 rounded-md text-sm',
                           'bg-surface-2 text-fg font-medium' => $item['aktif'],
                           'text-muted hover:text-fg hover:bg-surface-2' => ! $item['aktif'],
                       ])
                       :class="ciut && 'justify-center px-0'">
                        @if ($item['aktif'])
                            <span class="absolute left-0 top-2 bottom-2 w-0.5 rounded-full bg-accent"></span>
                        @endif
                        <x-ikon :name="$item['ikon']" size="18" class="shrink-0 {{ $item['warna'] }}" />
                        <span class="truncate" x-show="! ciut">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            @endforeach

            {{-- Panel admin (owner / supervisor dengan izin admin.akses) --}}
            @if ($bisaAdmin)
                <div class="label px-3 mt-3 mb-1.5" x-show="! ciut">Pengelolaan</div>
                <div class="h-px bg-line mx-2 my-3" x-show="ciut" x-cloak></div>
                <a href="{{ url('/admin') }}" title="Panel Admin"
                   class="flex items-center gap-3 h-10 px-3 rounded-md text-sm text-muted hover:text-fg hover:bg-surface-2"
                   :class="ciut && 'justify-center px-0'">
                    <x-ikon name="admin" size="18" class="shrink-0 text-ik-indigo" />
                    <span class="truncate" x-show="! ciut">Panel Admin</span>
                </a>
            @endif
        </nav>

        {{-- Kartu shift & user --}}
        <div class="border-t border-line p-2" x-data="{ menuUser: false }" @click.outside="menuUser = false">
            <div class="relative">
                <button type="button" @click="menuUser = ! menuUser"
                        class="w-full flex items-center gap-3 rounded-md p-2 hover:bg-surface-2 text-left"
                        :class="ciut && 'justify-center'">
                    <span class="h-9 w-9 shrink-0 rounded-md grid place-items-center bg-surface-2 border border-line text-sm font-semibold">
                        {{ $inisial }}
                    </span>
                    <span class="min-w-0 flex-1" x-show="! ciut">
                        <span class="block text-sm font-medium truncate">{{ $user?->name }}</span>
                        <span class="block label truncate">
                            {{ $shiftAktif ? 'Shift '.$shiftAktif->dibuka_pada->format('H:i') : 'Shift belum dibuka' }}
                        </span>
                    </span>
                    <x-ikon name="chevron" size="16" class="text-muted shrink-0" x-show="! ciut" />
                </button>

                <div x-show="menuUser" x-transition.opacity.duration.150ms x-cloak
                     class="surface absolute bottom-full left-0 mb-2 w-56 p-1 shadow-xl z-40">
                    @if ($shiftAktif)
                        <a href="{{ route('shift.tutup') }}" wire:navigate class="flex items-center gap-2 px-3 py-2 rounded-md hover:bg-surface-2 text-sm">
                            <x-ikon name="kas" size="16" class="text-ik-kuning" /> Tutup Kas
                        </a>
                    @endif
                    @if ($bisaGantiCabang)
                        <a href="{{ route('pilih-cabang') }}" wire:navigate class="flex items-center gap-2 px-3 py-2 rounded-md hover:bg-surface-2 text-sm">
                            <x-ikon name="cabang" size="16" class="text-ik-biru" /> Ganti Cabang
                        </a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full flex items-center gap-2 px-3 py-2 rounded-md hover:bg-surface-2 text-sm text-danger">
                            <x-ikon name="keluar" size="16" /> Keluar
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </aside>

    {{-- ===================== KONTEN ===================== --}}
    <div class="flex-1 min-w-0 flex flex-col">

        {{-- Header --}}
        <header class="sticky top-0 z-30 border-b border-line bg-surface/95 backdrop-blur">
            <div class="h-16 px-4 lg:px-6 flex items-center gap-4">
                {{-- Ciutkan sidebar (desktop) --}}
                <button type="button" class="hidden lg:inline-flex btn btn-ghost btn-ikon text-muted" @click="ubahCiut()" title="Ciutkan / lebarkan menu">
                    <x-ikon name="sidebar" size="18" />
                </button>

                {{-- Identitas (HP) --}}
                <div class="lg:hidden min-w-0">
                    <div class="font-semibold leading-tight truncate">{{ $cabangAktif?->nama ?? config('app.name') }}</div>
                    <div class="label truncate">
                        {{ $shiftAktif ? 'Shift '.$shiftAktif->dibuka_pada->format('H:i').' · '.$user->name : 'Shift belum dibuka' }}
                    </div>
                </div>

                {{-- Ringkasan (desktop) --}}
                @if ($cabangAktif)
                    <div class="hidden lg:block">
                        <livewire:operator.ringkasan-header />
                    </div>
                @endif

                {{-- Jam --}}
                <div class="ml-auto flex items-center gap-2"
                     x-data="{
                         teks: '',
                         init() {
                             const f = () => {
                                 const now = window.jamServer ? window.jamServer.sekarang() : Date.now();
                                 this.teks = new Date(now).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                             };
                             f();
                             setInterval(f, 1000);
                         },
                     }">
                    <button type="button" class="btn btn-ghost btn-ikon text-muted"
                            x-data="{ tutup: document.documentElement.classList.contains('sembunyi-uang') }"
                            @sembunyi-uang.window="tutup = $event.detail"
                            @click="ubahSembunyiUang()"
                            :title="tutup ? 'Tampilkan nominal' : 'Sembunyikan nominal (untuk foto layar)'"
                            aria-label="Sembunyikan / tampilkan nominal">
                        <x-ikon name="mata" size="18" x-show="! tutup" />
                        <x-ikon name="mata-tutup" size="18" x-show="tutup" x-cloak />
                    </button>
                    <span class="hidden sm:inline-flex items-center gap-1.5 label">
                        <span class="dot text-st-kosong"></span> Online
                    </span>
                    <span class="num font-semibold text-lg" x-text="teks"></span>
                    @if ($zona)
                        <span class="label">{{ $zona }}</span>
                    @endif
                </div>
            </div>
        </header>

        <main class="flex-1 px-4 lg:px-6 py-4 lg:py-5 pb-24 lg:pb-6">
            {{ $slot }}
        </main>
    </div>
</div>

{{-- ===================== NAVIGASI BAWAH (HP) ===================== --}}
<nav class="lg:hidden fixed bottom-0 inset-x-0 z-30 border-t border-line bg-surface"
     style="padding-bottom: env(safe-area-inset-bottom, 0px);">
    <div class="grid grid-cols-4">
        @foreach ($menuBawah as $item)
            <a href="{{ route($item['route']) }}" wire:navigate
               @class([
                   'flex flex-col items-center gap-1 py-2 text-[11px]',
                   'text-accent' => $item['aktif'],
                   'text-muted' => ! $item['aktif'],
               ])>
                <x-ikon :name="$item['ikon']" size="20" class="{{ $item['warna'] }}" />
                {{ $item['label'] }}
            </a>
        @endforeach
        <button type="button" @click="lainnya = true" class="flex flex-col items-center gap-1 py-2 text-[11px] text-muted">
            <x-ikon name="lainnya" size="20" />
            Lainnya
        </button>
    </div>
</nav>

{{-- ===================== PANEL LAINNYA (HP) ===================== --}}
<div x-show="lainnya" x-cloak class="lg:hidden fixed inset-0 z-50 flex items-end" @keydown.escape.window="lainnya = false">
    <div class="absolute inset-0 bg-black/60" x-show="lainnya" x-transition.opacity.duration.150ms @click="lainnya = false"></div>
    <div class="relative w-full bg-surface border-t border-line rounded-t-lg max-h-[85vh] overflow-y-auto"
         x-show="lainnya"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="translate-y-full"
         x-transition:enter-end="translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="translate-y-0"
         x-transition:leave-end="translate-y-full"
         style="padding-bottom: env(safe-area-inset-bottom, 0px);">

        <div class="flex items-center justify-between px-4 py-3 border-b border-line">
            <div>
                <div class="font-semibold">{{ $user?->name }}</div>
                <div class="label">{{ $cabangAktif?->nama }}</div>
            </div>
            <button type="button" class="btn btn-ghost btn-ikon text-muted" @click="lainnya = false" aria-label="Tutup">
                <x-ikon name="tutup" size="18" />
            </button>
        </div>

        <div class="p-3">
            @foreach ($menu as $grup => $items)
                <div class="label px-2 mt-3 mb-1.5 first:mt-0">{{ $grup }}</div>
                <div class="grid grid-cols-3 gap-2">
                    @foreach ($items as $item)
                        <a href="{{ route($item['route']) }}" wire:navigate @click="lainnya = false"
                           @class([
                               'kartu flex flex-col items-center gap-1.5 py-3 text-xs text-center',
                               'text-accent border-accent' => $item['aktif'],
                           ])>
                            <x-ikon :name="$item['ikon']" size="20" class="{{ $item['warna'] }}" />
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
            @endforeach

            <div class="label px-2 mt-4 mb-1.5">Akun</div>
            <div class="kartu divide-y divide-line">
                @if ($bisaAdmin)
                    <a href="{{ url('/admin') }}" class="flex items-center gap-3 px-3 py-3 text-sm">
                        <x-ikon name="admin" size="18" class="text-ik-indigo" /> Panel Admin
                    </a>
                @endif
                @if ($shiftAktif)
                    <a href="{{ route('shift.tutup') }}" wire:navigate @click="lainnya = false" class="flex items-center gap-3 px-3 py-3 text-sm">
                        <x-ikon name="kas" size="18" class="text-ik-kuning" /> Tutup Kas
                    </a>
                @endif
                @if ($bisaGantiCabang)
                    <a href="{{ route('pilih-cabang') }}" wire:navigate @click="lainnya = false" class="flex items-center gap-3 px-3 py-3 text-sm">
                        <x-ikon name="cabang" size="18" class="text-ik-biru" /> Ganti Cabang
                    </a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full flex items-center gap-3 px-3 py-3 text-sm text-danger">
                        <x-ikon name="keluar" size="18" /> Keluar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- Pratinjau struk (dipakai halaman rental, POS & transaksi) --}}
@if ($cabangAktif)
    <livewire:operator.pratinjau-struk />
    {{-- Panggilan "Panggil Kasir" dari TV Agent --}}
    <livewire:operator.panggilan-tv />
@endif

@livewireScripts
</body>
</html>
