{{-- Pemilih bahasa (menu akun kasir, halaman login, menu admin). $kelas = kelas tambahan select --}}
<form method="POST" action="{{ route('bahasa') }}" class="flex items-center gap-2 {{ $bungkus ?? '' }}">
    @csrf
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="shrink-0 opacity-70">
        <circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>
    </svg>
    <label class="sr-only" for="pilih-bahasa-{{ $id ?? 'x' }}">{{ __('Bahasa') }}</label>
    <select id="pilih-bahasa-{{ $id ?? 'x' }}" name="kode" onchange="this.form.submit()" class="{{ $kelas ?? 'input h-9 text-sm py-0' }}">
        @foreach (\App\Support\Bahasa::DAFTAR as $kode => $nama)
            <option value="{{ $kode }}" @selected(app()->getLocale() === $kode)>{{ $nama }}</option>
        @endforeach
    </select>
</form>
