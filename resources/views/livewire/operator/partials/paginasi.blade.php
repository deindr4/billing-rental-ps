{{-- Paginasi ringkas tampilan daftar. Variabel: $p (LengthAwarePaginator), $satuan (mis. 'unit', 'sewa') --}}
@if ($p->hasPages())
    <div class="flex flex-wrap items-center justify-between gap-2 mt-3">
        <span class="text-xs text-muted">
            {{ $p->firstItem() }}–{{ $p->lastItem() }} dari {{ $p->total() }} {{ $satuan }}
        </span>
        <div class="flex items-center gap-1">
            <button type="button" wire:click="previousPage" class="btn h-9 px-3 text-sm" @disabled($p->onFirstPage())>‹ Sebelumnya</button>
            @foreach (range(1, $p->lastPage()) as $n)
                @if ($n === 1 || $n === $p->lastPage() || abs($n - $p->currentPage()) <= 1)
                    <button type="button" wire:click="gotoPage({{ $n }})"
                            @class(['btn h-9 w-9 text-sm num', 'btn-primary' => $n === $p->currentPage()])>{{ $n }}</button>
                @elseif (abs($n - $p->currentPage()) === 2)
                    <span class="px-1 text-muted">…</span>
                @endif
            @endforeach
            <button type="button" wire:click="nextPage" class="btn h-9 px-3 text-sm" @disabled(! $p->hasMorePages())>Berikutnya ›</button>
        </div>
    </div>
@endif
