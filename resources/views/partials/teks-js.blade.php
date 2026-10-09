{{-- Terjemahan untuk teks di JavaScript: t('Kunci :x', { x: 1 }). Daftar kunci di App\Support\Bahasa::TEKS_JS --}}
<script>
    window.TEKS = @json(collect(\App\Support\Bahasa::TEKS_JS)->mapWithKeys(fn ($k) => [$k => __($k)]));
    window.LOKAL = @json(\App\Support\Bahasa::html());
    window.t = (kunci, isi = {}) => Object.entries(isi).reduce(
        (teks, [k, v]) => teks.replaceAll(':' + k, v), (window.TEKS && window.TEKS[kunci]) || kunci);
</script>
