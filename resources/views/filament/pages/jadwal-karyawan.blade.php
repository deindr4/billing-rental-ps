<x-filament-panels::page>
    @php
        $karyawan = $this->karyawan();
        $template = $this->template();
    @endphp

    @if ($template->isEmpty())
        <x-filament::section>
            Belum ada jam shift. Buat dulu di <a href="{{ \App\Filament\Resources\TemplateShift\TemplateShiftResource::getUrl('create') }}" class="underline">Karyawan → Jam shift</a>
            (mis. Pagi 10:00–17:00, Malam 17:00–02:00).
        </x-filament::section>
    @elseif ($karyawan->isEmpty())
        <x-filament::section>Belum ada karyawan aktif. Tambahkan di Karyawan → Karyawan.</x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="description">Berulang tiap minggu. Kosong = libur. Dipakai untuk menghitung terlambat & pulang cepat saat absen.</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-3 font-medium">Karyawan</th>
                            @foreach (\App\Models\JadwalKaryawan::HARI as $hari => $nama)
                                <th class="py-2 px-1 font-medium">{{ $nama }}</th>
                            @endforeach
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($karyawan as $k)
                            <tr wire:key="jk-{{ $k->id }}">
                                <td class="py-2 pr-3 whitespace-nowrap">
                                    <div class="font-medium">{{ $k->nama }}</div>
                                    <div class="text-xs text-gray-500">{{ $k->jabatan }}{{ $k->cabang ? ' · '.$k->cabang->kode : '' }}</div>
                                </td>
                                @foreach (\App\Models\JadwalKaryawan::HARI as $hari => $nama)
                                    <td class="py-2 px-1">
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select wire:model="jadwal.{{ $k->id }}.{{ $hari }}">
                                                <option value="">Libur</option>
                                                @foreach ($template as $t)
                                                    <option value="{{ $t->id }}">{{ $t->nama }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    </td>
                                @endforeach
                                <td class="py-2 pl-1">
                                    <x-filament::link tag="button" wire:click="samakan('{{ $k->id }}')" size="sm" title="Samakan semua hari dengan Senin">= Senin</x-filament::link>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 text-xs text-gray-500">
                @foreach ($template as $t)
                    <span class="mr-3"><b>{{ $t->nama }}</b> {{ $t->label() }}</span>
                @endforeach
            </div>
        </x-filament::section>

        <div>
            <x-filament::button wire:click="simpan">Simpan jadwal</x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
