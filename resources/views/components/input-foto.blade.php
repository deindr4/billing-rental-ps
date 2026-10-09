{{--
    Tombol ambil foto yang jelas (pengganti "Choose file" bawaan browser) + status unggah + pratinjau.
    <x-input-foto wire:model="fotoKtp" :nilai="$fotoKtp" kamera="environment" label="Foto KTP" />
    <x-input-foto wire:model="fotoKondisi" :nilai="$fotoKondisi" multiple label="Foto kondisi" />
    kamera: user (depan) | environment (belakang) | kosong = boleh pilih dari galeri
    lama: path foto privat yang sudah tersimpan (ditampilkan bila belum ada foto baru)
--}}
@props(['label' => 'Ambil foto', 'kamera' => null, 'multiple' => false, 'lama' => null, 'nilai' => null])

@php
    $model = $attributes->wire('model')->value();
    $daftar = array_values(array_filter(is_array($nilai) ? $nilai : [$nilai]));
    $bisaLihat = fn ($f) => is_object($f) && method_exists($f, 'isPreviewable') && $f->isPreviewable();
@endphp

<div>
    <label @class(['flex items-center justify-center gap-2 h-11 px-4 rounded-md border-2 border-dashed cursor-pointer text-sm font-medium transition',
                   'border-accent text-accent bg-accent/5' => $daftar !== [],
                   'border-line text-fg hover:border-accent hover:text-accent' => $daftar === []])>
        <x-ikon name="kamera" size="18" />
        <span wire:loading.remove wire:target="{{ $model }}">
            {{ $daftar === [] ? $label : ($multiple ? count($daftar).' foto · ambil ulang' : 'Ganti foto') }}
        </span>
        <span wire:loading wire:target="{{ $model }}">Mengunggah…</span>
        <input type="file" accept="image/*" class="sr-only" wire:model="{{ $model }}"
               @if ($kamera) capture="{{ $kamera }}" @endif @if ($multiple) multiple @endif>
    </label>

    @if ($daftar !== [])
        <div class="flex flex-wrap gap-2 mt-2">
            @foreach ($daftar as $f)
                @if ($bisaLihat($f))
                    <img src="{{ $f->temporaryUrl() }}" class="h-24 rounded-md object-cover border border-line" alt="">
                @endif
            @endforeach
        </div>
    @elseif ($lama)
        <img src="{{ \App\Support\FotoPrivat::url($lama) }}" class="mt-2 h-24 rounded-md object-cover border border-line" alt="">
    @endif

    @error($model) <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
</div>
