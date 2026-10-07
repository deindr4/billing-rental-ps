@php
    $aksen = \App\Support\Tema::aksen();
    $kontras = \App\Support\Tema::aksenKontras();
    $mode = \App\Support\Tema::mode();
@endphp
<!DOCTYPE html>
<html lang="id" data-theme="{{ $mode }}" style="--accent: {{ $aksen }}; --accent-contrast: {{ $kontras }};">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Booking' }}</title>
    <x-ikon-aplikasi />
    @vite(['resources/css/booking.css', 'resources/js/booking.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-bg text-fg">
    {{ $slot }}
    <x-hak-cipta class="py-4" />
    @livewireScripts
</body>
</html>
