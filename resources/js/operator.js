import { toast, alert, confirm, loading, close } from './modules/alert.js';
import QRCode from 'qrcode';

// Akses global: window.ui.toast({...}), window.ui.confirm({...})
window.ui = { toast, alert, confirm, loading, close };

// QR (QRIS dinamis) ke elemen <canvas>: x-init="buatQr($el, teks)"
window.buatQr = (canvas, teks) => QRCode.toCanvas(canvas, teks, { width: 260, margin: 1, errorCorrectionLevel: 'M' });

/* ------------------------------------------------------------------
 | Jam server
 | Selisih jam browser vs server dihitung SEKALI saat halaman dibuka,
 | lalu dipakai semua timer (tidak dihitung ulang tiap refresh data).
 * ------------------------------------------------------------------ */
const jam = {
    offset: null,

    set(serverNow) {
        if (this.offset === null) {
            this.offset = serverNow - Date.now();
        }
    },

    sekarang() {
        return Date.now() + (this.offset ?? 0);
    },
};

window.jamServer = jam;

/* Bunyi "ding-dong" singkat tanpa file audio (Web Audio API) */
function bunyiPanggilan() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();

        [[880, 0], [660, 0.22], [880, 0.6], [660, 0.82]].forEach(([frek, mulai]) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = frek;
            gain.gain.setValueAtTime(0.0001, ctx.currentTime + mulai);
            gain.gain.exponentialRampToValueAtTime(0.35, ctx.currentTime + mulai + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + mulai + 0.2);
            osc.connect(gain).connect(ctx.destination);
            osc.start(ctx.currentTime + mulai);
            osc.stop(ctx.currentTime + mulai + 0.22);
        });

        setTimeout(() => ctx.close(), 1500);
    } catch {
        // browser tanpa Web Audio: cukup notifikasi
    }
}

/* Format detik -> 00:00:00 */
function formatDetik(detik) {
    const d = Math.max(0, Math.floor(detik));
    const j = Math.floor(d / 3600);
    const m = Math.floor((d % 3600) / 60);
    const s = d % 60;

    return [j, m, s].map((n) => String(n).padStart(2, '0')).join(':');
}

document.addEventListener('alpine:init', () => {
    // Magic Alpine: $confirm({...}), $toast({...})
    window.Alpine.magic('confirm', () => (options) => confirm(options));
    window.Alpine.magic('toast', () => (options) => toast(options));

    /*
     | Timer sesi
     | paket : hitung mundur ke jam berakhir
     | open  : hitung maju dari jam mulai (dikurangi total jeda)
     | Semua waktu dalam milidetik.
     */
    window.Alpine.data('timerSesi', (c) => ({
        teks: '--:--:--',
        hampir: false,
        habis: false,
        pilihGame: false,
        timeout: null,

        init() {
            jam.set(c.serverNow);
            this.tick();

            if (!c.dijeda) {
                this.jadwalkan();
            }
        },

        destroy() {
            clearTimeout(this.timeout);
        },

        // Detak berikutnya tepat di awal detik baru (+10 ms toleransi)
        jadwalkan() {
            const tunda = 1000 - (jam.sekarang() % 1000) + 10;

            this.timeout = setTimeout(() => {
                this.tick();
                this.jadwalkan();
            }, tunda);
        },

        sekarang() {
            return c.dijeda ?? jam.sekarang();
        },

        tick() {
            // Waktu pilih game: hitung mundur ke jam mulai sewa
            this.pilihGame = !c.dijeda && jam.sekarang() < c.mulai;
            if (this.pilihGame) {
                this.teks = formatDetik(Math.ceil((c.mulai - jam.sekarang()) / 1000));
                this.hampir = false;
                this.habis = false;
                return;
            }

            if (c.mode === 'paket') {
                const sisa = Math.max(0, Math.ceil((c.berakhir - this.sekarang()) / 1000));
                this.teks = formatDetik(sisa);
                this.habis = sisa === 0;
                this.hampir = !this.habis && sisa <= c.peringatanMenit * 60;
            } else {
                const jalan = Math.floor((this.sekarang() - c.mulai) / 1000) - c.jedaDetik;
                this.teks = formatDetik(jalan);
            }
        },
    }));
});

document.addEventListener('livewire:init', () => {
    // Event dari komponen Livewire (trait WithAlert)
    window.Livewire.on('ui:toast', (event) => toast(event));
    window.Livewire.on('ui:alert', (event) => alert(event));

    // Pelanggan menekan "Panggil Kasir" di TV: bunyi + notifikasi
    window.Livewire.on('ui:panggil-kasir', ({ unit }) => {
        bunyiPanggilan();
        alert({ icon: 'info', title: `${unit} memanggil kasir`, text: 'Pelanggan meminta bantuan di unit tersebut.' });
    });

    // Pembayaran sukses: tawarkan pratinjau struk, cetak dari panel pratinjau
    window.Livewire.on('ui:bayar-berhasil', async ({ title, text, transaksiId }) => {
        const lihat = await confirm({ icon: 'success', title, text, confirmText: 'Lihat struk', cancelText: 'Tutup' });

        if (lihat) {
            window.Livewire.dispatch('buka-pratinjau-struk', { transaksiId });
        }
    });

    // Error request Livewire ditampilkan dengan SweetAlert
    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            preventDefault();

            if (status === 419) {
                alert({ icon: 'warning', title: 'Sesi berakhir', text: 'Silakan muat ulang halaman.' })
                    .then(() => window.location.reload());
                return;
            }

            if (status === 403) {
                alert({ icon: 'error', title: 'Akses ditolak', text: 'Anda tidak punya izin untuk aksi ini.' });
                return;
            }

            alert({ icon: 'error', title: 'Terjadi kesalahan', text: `Kode ${status}. Coba lagi.` });
        });
    });
});

// Flash setelah redirect (termasuk wire:navigate)
document.addEventListener('livewire:navigated', () => {
    const raw = document.body.dataset.flash;
    if (!raw) return;

    delete document.body.dataset.flash;

    try {
        const flash = JSON.parse(raw);
        flash.type === 'alert' ? alert(flash) : toast(flash);
    } catch {
        // abaikan flash yang tidak valid
    }
});
