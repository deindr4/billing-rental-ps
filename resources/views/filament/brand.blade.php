{{-- Brand panel admin: logo rental (atau logo aplikasi) + nama aplikasi --}}
<div style="display:flex; align-items:center; gap:10px;">
    <img src="{{ \App\Support\Tema::logoAtauBawaan() }}" alt="Logo" style="height:2.25rem; width:2.25rem; object-fit:contain;">
    <span style="font-weight:700; font-size:1.05rem; white-space:nowrap;">{{ config('app.name') }}</span>
</div>
