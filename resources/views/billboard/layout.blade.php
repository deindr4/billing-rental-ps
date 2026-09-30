<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0a1420">
    <title>{{ $title ?? 'Status rental' }}</title>
    @if (\App\Support\Tema::logoUrl())
        <link rel="icon" type="image/webp" href="{{ \App\Support\Tema::logoUrl() }}">
    @endif
    @vite(['resources/css/billboard.css', 'resources/js/billboard.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-bg text-fg">
    {{ $slot }}
    @livewireScripts
</body>
</html>
