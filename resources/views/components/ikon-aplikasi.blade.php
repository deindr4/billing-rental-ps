{{-- Ikon tab peramban & layar utama HP: logo rental (Admin → Tampilan) atau logo aplikasi bawaan --}}
@php $logoRental = \App\Support\Tema::logoUrl(); @endphp
@if ($logoRental)
    <link rel="icon" type="image/webp" href="{{ $logoRental }}">
@else
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="/logo-192.png" sizes="192x192">
@endif
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
