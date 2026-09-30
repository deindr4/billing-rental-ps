{{--
    Input nominal Rupiah dengan pemisah ribuan otomatis.
    <x-input-uang wire:model="kasAwal" />        → dikirim saat submit
    <x-input-uang wire:model.live="jumlah" />    → dikirim setiap diketik (untuk hitung kembalian)
    Tampil: 20.000  → nilai Livewire: 20000
--}}
@props(['placeholder' => '0'])

@php
    $wireModel = $attributes->wire('model');
    $model = $wireModel->value();
    $live = $wireModel->hasModifier('live');
@endphp

<div class="relative"
     x-data="{
         nilai: $wire.entangle('{{ $model }}'){{ $live ? '.live' : '' }},
         tampil: '',
         angka(teks) {
             const digit = String(teks ?? '').replace(/\D/g, '');
             return digit === '' ? null : parseInt(digit, 10);
         },
         format(n) {
             return (n === null || n === '' || isNaN(n)) ? '' : Number(n).toLocaleString('id-ID');
         },
         ketik(e) {
             const n = this.angka(e.target.value);
             this.nilai = n;
             this.tampil = this.format(n);
             e.target.value = this.tampil;
         },
         init() {
             this.tampil = this.format(this.nilai);
             this.$watch('nilai', (v) => {
                 if (this.angka(this.tampil) !== v) this.tampil = this.format(v);
             });
         },
     }">
    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-muted pointer-events-none">Rp</span>
    <input type="text"
           inputmode="numeric"
           autocomplete="off"
           placeholder="{{ $placeholder }}"
           :value="tampil"
           @input="ketik($event)"
           {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'input num pl-10']) }}>
</div>
