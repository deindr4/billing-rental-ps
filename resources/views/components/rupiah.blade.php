@props(['nilai' => 0])

<span {{ $attributes->merge(['class' => 'num']) }}>Rp {{ number_format((int) $nilai, 0, ',', '.') }}</span>
