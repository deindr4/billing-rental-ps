<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    <x-ikon-aplikasi />

    @vite(['resources/css/operator.css', 'resources/js/operator.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-bg text-fg">
    {{ $slot }}

    @livewireScripts
</body>
</html>
