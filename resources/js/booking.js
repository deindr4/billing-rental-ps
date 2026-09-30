import { toast, alert, confirm, loading, close } from './modules/alert.js';

window.ui = { toast, alert, confirm, loading, close };

document.addEventListener('alpine:init', () => {
    window.Alpine.magic('confirm', () => (options) => confirm(options));
    window.Alpine.magic('toast', () => (options) => toast(options));
});

document.addEventListener('livewire:init', () => {
    window.Livewire.on('ui:toast', (event) => toast(event));
    window.Livewire.on('ui:alert', (event) => alert(event));
});

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
