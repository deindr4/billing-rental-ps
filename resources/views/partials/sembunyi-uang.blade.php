{{--
    Sembunyikan nominal (untuk foto layar): tombol mata menambah class "sembunyi-uang" di <html>.
    Yang disembunyikan: <x-rupiah rahasia> dan semua <x-rupiah> di dalam [data-rahasia] (laporan).
    Pilihan disimpan di browser ini saja (localStorage), dipakai bersama halaman kasir & panel admin.
--}}
<style>
    .rp-tutup { display: none; }
    html.sembunyi-uang :is([data-rahasia], .rp-rahasia) .rp-nilai { display: none; }
    html.sembunyi-uang :is([data-rahasia], .rp-rahasia) .rp-tutup { display: inline; }
</style>
<script>
    (() => {
        const terapkan = () => {
            try {
                document.documentElement.classList.toggle('sembunyi-uang', localStorage.getItem('sembunyi-uang') === '1');
            } catch (e) {}
        };
        terapkan();
        document.addEventListener('livewire:navigated', terapkan);

        window.ubahSembunyiUang = () => {
            const tutup = ! document.documentElement.classList.contains('sembunyi-uang');
            document.documentElement.classList.toggle('sembunyi-uang', tutup);
            try { localStorage.setItem('sembunyi-uang', tutup ? '1' : '0'); } catch (e) {}
            window.dispatchEvent(new CustomEvent('sembunyi-uang', { detail: tutup }));
        };
    })();
</script>
