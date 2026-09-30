/*
 | SweetAlert2 wrapper
 | Library dimuat saat pertama kali dipakai (lazy), jadi tidak menambah beban awal halaman.
 */

let swalPromise = null;

function loadSwal() {
    swalPromise ??= import('sweetalert2').then((m) => m.default);
    return swalPromise;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

const baseClass = {
    popup: 'swal-popup',
    title: 'swal-title',
    htmlContainer: 'swal-text',
    actions: 'swal-actions',
    confirmButton: 'btn btn-primary',
    cancelButton: 'btn',
    denyButton: 'btn btn-danger',
    input: 'input',
};

export async function toast({ icon = 'success', title = '', timer = 2500 } = {}) {
    const Swal = await loadSwal();

    return Swal.fire({
        toast: true,
        position: 'top-end',
        icon,
        title,
        timer,
        timerProgressBar: true,
        showConfirmButton: false,
        customClass: {
            popup: 'swal-popup swal-toast',
            title: 'swal-title',
        },
    });
}

export async function alert({ icon = 'info', title = '', text = '' } = {}) {
    const Swal = await loadSwal();

    return Swal.fire({
        icon,
        title,
        text,
        confirmButtonText: 'OK',
        buttonsStyling: false,
        customClass: baseClass,
    });
}

/**
 * Konfirmasi aksi.
 * Return: null jika batal, object { reason?, pin? } jika dikonfirmasi.
 */
export async function confirm({
    title = 'Yakin?',
    text = '',
    icon = 'warning',
    confirmText = 'Ya, lanjutkan',
    cancelText = 'Batal',
    danger = false,
    pin = false,
    reason = false,
} = {}) {
    const Swal = await loadSwal();

    let html = text ? `<p class="swal-desc">${escapeHtml(text)}</p>` : '';

    if (reason) {
        html += '<textarea id="swal-reason" class="input swal-textarea" placeholder="Alasan (wajib)"></textarea>';
    }

    if (pin) {
        html += '<input id="swal-pin" type="password" inputmode="numeric" autocomplete="off" class="input swal-pin" placeholder="PIN">';
    }

    const result = await Swal.fire({
        icon,
        title,
        html,
        showCancelButton: true,
        reverseButtons: true,
        focusCancel: danger,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        buttonsStyling: false,
        customClass: {
            ...baseClass,
            confirmButton: danger ? 'btn btn-danger' : 'btn btn-primary',
        },
        didOpen: () => {
            const first = document.getElementById(reason ? 'swal-reason' : 'swal-pin');
            first?.focus();
        },
        preConfirm: () => {
            const data = {};

            if (reason) {
                const value = document.getElementById('swal-reason').value.trim();
                if (!value) {
                    Swal.showValidationMessage('Alasan wajib diisi');
                    return false;
                }
                data.reason = value;
            }

            if (pin) {
                const value = document.getElementById('swal-pin').value.trim();
                if (!value) {
                    Swal.showValidationMessage('PIN wajib diisi');
                    return false;
                }
                data.pin = value;
            }

            return data;
        },
    });

    return result.isConfirmed ? result.value : null;
}

export async function loading(title = 'Memproses...') {
    const Swal = await loadSwal();

    Swal.fire({
        title,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        customClass: { popup: 'swal-popup', title: 'swal-title' },
        didOpen: () => Swal.showLoading(),
    });
}

export async function close() {
    const Swal = await loadSwal();
    Swal.close();
}
