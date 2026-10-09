<?php

namespace App\Livewire\Concerns;

/**
 * Ubah unggahan sementara Livewire menjadi file lokal untuk dikompres (FotoPrivat / Gambar).
 * Isi dibaca lewat disk sementara Livewire — path aslinya belum tentu bisa dibaca langsung.
 * Panggil hapusSementara() setelah dipakai.
 */
trait UnggahSementara
{
    /** @var array<int, string> */
    private array $berkasSementara = [];

    protected function keFile($unggahan): ?string
    {
        if (! $unggahan || ! method_exists($unggahan, 'get')) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ungg');
        file_put_contents($tmp, (string) $unggahan->get());
        $this->berkasSementara[] = $tmp;

        return $tmp;
    }

    /** @return array<int, string> */
    protected function keFileBanyak(array $unggahan): array
    {
        return array_values(array_filter(array_map(fn ($u) => $this->keFile($u), $unggahan)));
    }

    protected function hapusSementara(): void
    {
        foreach ($this->berkasSementara as $f) {
            @unlink($f);
        }

        $this->berkasSementara = [];
    }
}
