{{--
    Tombol aksi dengan konfirmasi SweetAlert.

    Contoh:
    <x-confirm-button action="selesaikanSesi" :params="[$sesi->id]"
        title="Selesaikan sesi?" text="TV akan dikunci dan tagihan ditampilkan." danger>
        Selesai
    </x-confirm-button>

    <x-confirm-button action="batalkanTransaksi" :params="[$trx->id]"
        title="Batalkan transaksi?" danger reason pin>
        Batalkan
    </x-confirm-button>

    Method Livewire menerima parameter tambahan terakhir berisi hasil konfirmasi:
    public function batalkanTransaksi(string $id, array $confirm = []) { $confirm['reason'], $confirm['pin'] }

    Teks diterjemahkan oleh pemanggil: :title="__('Batalkan :nomor?', ['nomor' => $x])" (bawaan: Yakin? / Ya, lanjutkan)
--}}

@props([
    'action',
    'params' => [],
    'title' => null,
    'text' => '',
    'confirmText' => null,
    'danger' => false,
    'pin' => false,
    'reason' => false,
])

<button
    type="button"
    {{ $attributes->class(['btn', 'btn-danger' => $danger]) }}
    x-data
    x-on:click="
        const result = await $confirm(@js([
            'title' => $title ?? __('Yakin?'),
            'text' => $text,
            'confirmText' => $confirmText ?? __('Ya, lanjutkan'),
            'danger' => (bool) $danger,
            'pin' => (bool) $pin,
            'reason' => (bool) $reason,
        ]));

        if (result) {
            $wire.call(@js($action), ...@js(array_values((array) $params)), result);
        }
    "
>
    {{ $slot }}
</button>
