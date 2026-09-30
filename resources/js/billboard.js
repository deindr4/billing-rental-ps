// Entry JS billboard publik: jam & timer stasiun berjalan di browser (data diperbarui Livewire tiap 10 detik).

let selisih = 0;
window.aturWaktuServer = (ms) => { selisih = ms - Date.now(); };
const sekarang = () => Date.now() + selisih;

const duaDigit = (n) => String(n).padStart(2, '0');
const formatDurasi = (detik) => {
    detik = Math.max(0, Math.floor(detik));
    const j = Math.floor(detik / 3600), m = Math.floor((detik % 3600) / 60), d = detik % 60;
    return `${duaDigit(j)}:${duaDigit(m)}:${duaDigit(d)}`;
};

// Elemen bisa diganti Livewire saat data diperbarui, jadi dicari ulang tiap detik
setInterval(() => {
    const now = sekarang();
    document.querySelectorAll('[data-berakhir]').forEach((el) => {
        el.textContent = formatDurasi((Number(el.dataset.berakhir) - now) / 1000);
    });
    document.querySelectorAll('[data-mulai]').forEach((el) => {
        el.textContent = formatDurasi((now - Number(el.dataset.mulai)) / 1000);
    });
    document.querySelectorAll('[data-jam]').forEach((el) => {
        const t = new Date(now);
        el.textContent = `${duaDigit(t.getHours())}:${duaDigit(t.getMinutes())}`;
    });
}, 1000);
