<!DOCTYPE html>
<html lang="{{ \App\Support\Bahasa::html() }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? __($title) : config('app.name') }}</title>
    <x-ikon-aplikasi />
    @include('partials.teks-js')

    @vite(['resources/css/operator.css', 'resources/js/operator.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-bg text-fg">
    {{ $slot }}
    <x-hak-cipta class="fixed bottom-3 inset-x-0 pointer-events-none" />

    @livewireScripts
</body>
</html>
