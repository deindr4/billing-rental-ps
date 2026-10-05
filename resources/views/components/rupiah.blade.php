@props(['nilai' => 0, 'rahasia' => false])

{{-- rahasia / di dalam [data-rahasia]: angka diganti "Rp *******" saat tombol mata (sembunyikan nominal) aktif --}}
<span {{ $attributes->class(['num', 'rp-rahasia' => $rahasia]) }}><span class="rp-nilai">Rp {{ number_format((int) $nilai, 0, ',', '.') }}</span><span class="rp-tutup" aria-hidden="true">Rp *******</span></span>
