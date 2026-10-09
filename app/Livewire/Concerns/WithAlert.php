<?php

namespace App\Livewire\Concerns;

/**
 * Helper SweetAlert untuk komponen Livewire.
 *
 * Pemakaian:
 *   use WithAlert;
 *   $this->success('Sesi dimulai');
 *   $this->error('PIN salah');
 *   $this->alert('Gagal', 'Unit sedang dipakai', 'error');
 *   $this->flashSuccess('Tersimpan'); // sebelum redirect
 *
 * Teks tetap diterjemahkan di sini (kunci = teks Indonesia). Teks dengan isian variabel diterjemahkan pemanggil:
 *   $this->success(__('Sewa :nomor dibuat', ['nomor' => $x]));
 */
trait WithAlert
{
    /** Pesan error tetap ikut bahasa aktif (kunci = teks Indonesia) */
    public function addError($name, $message)
    {
        return parent::addError($name, is_string($message) ? __($message) : $message);
    }

    /** Pesan validasi kustom ['field.rule' => 'teks'] ikut bahasa aktif */
    public function validate($rules = null, $messages = [], $attributes = [])
    {
        return parent::validate($rules, array_map(fn ($m) => is_string($m) ? __($m) : $m, (array) $messages), $attributes);
    }

    protected function toast(string $title, string $icon = 'success'): void
    {
        $this->dispatch('ui:toast', icon: $icon, title: __($title));
    }

    protected function success(string $title): void
    {
        $this->toast($title, 'success');
    }

    protected function error(string $title): void
    {
        $this->toast($title, 'error');
    }

    protected function warning(string $title): void
    {
        $this->toast($title, 'warning');
    }

    protected function info(string $title): void
    {
        $this->toast($title, 'info');
    }

    protected function alert(string $title, string $text = '', string $icon = 'info'): void
    {
        $this->dispatch('ui:alert', icon: $icon, title: __($title), text: $text === '' ? '' : __($text));
    }

    protected function flashToast(string $title, string $icon = 'success'): void
    {
        session()->flash('ui', [
            'type' => 'toast',
            'icon' => $icon,
            'title' => __($title),
        ]);
    }

    protected function flashSuccess(string $title): void
    {
        $this->flashToast($title, 'success');
    }

    protected function flashAlert(string $title, string $text = '', string $icon = 'info'): void
    {
        session()->flash('ui', [
            'type' => 'alert',
            'icon' => $icon,
            'title' => __($title),
            'text' => $text === '' ? '' : __($text),
        ]);
    }
}
