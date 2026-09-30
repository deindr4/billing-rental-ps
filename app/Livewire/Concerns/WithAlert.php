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
 */
trait WithAlert
{
    protected function toast(string $title, string $icon = 'success'): void
    {
        $this->dispatch('ui:toast', icon: $icon, title: $title);
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
        $this->dispatch('ui:alert', icon: $icon, title: $title, text: $text);
    }

    protected function flashToast(string $title, string $icon = 'success'): void
    {
        session()->flash('ui', [
            'type' => 'toast',
            'icon' => $icon,
            'title' => $title,
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
            'title' => $title,
            'text' => $text,
        ]);
    }
}
