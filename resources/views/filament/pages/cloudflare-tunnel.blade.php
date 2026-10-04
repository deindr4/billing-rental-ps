<x-filament-panels::page>
    @php
        $baris = 'display:flex; justify-content:space-between; gap:12px; padding:6px 0; border-top:1px solid rgba(127,127,127,.18); font-size:14px;';
        $redup = 'opacity:.65;';
        [$label, $warna] = match ($status) {
            'running' => ['Menyala', '#22c55e'],
            'starting' => ['Sedang menyala…', '#eab308'],
            'stopping' => ['Sedang berhenti…', '#eab308'],
            'stopped' => ['Mati', '#94a3b8'],
            'tidak_terpasang' => ['Layanan belum terpasang — jalankan installer / patch terbaru', '#ef4444'],
            default => ['Tidak diketahui', '#94a3b8'],
        };
    @endphp

    <x-filament::section>
        <x-slot name="heading">Status</x-slot>
        <x-slot name="description">
            Buka aplikasi rental dari mana saja lewat domain Anda (HTTPS), tanpa membuka port router.
            Jaringan lokal (kasir, TV) tetap memakai alamat LAN seperti biasa.
        </x-slot>
        <div>
            <div style="{{ $baris }} border-top:none"><span style="{{ $redup }}">Layanan tunnel</span><b style="color: {{ $warna }}">{{ $label }}</b></div>
            <div style="{{ $baris }}"><span style="{{ $redup }}">Token</span>
                <span>{{ $adaToken ? 'Tersimpan'.($idTunnel ? ' · tunnel '.\Illuminate\Support\Str::limit($idTunnel, 13) : '') : 'Belum diisi' }}</span></div>
            <div style="{{ $baris }}"><span style="{{ $redup }}">Proxy tepercaya (TRUSTED_PROXIES)</span>
                <span style="color: {{ $proxyTepercaya === '' ? '#ef4444' : 'inherit' }}; text-align:right">{{ $proxyTepercaya ?: 'Belum diatur — login lewat tunnel akan gagal' }}</span></div>
        </div>
        @if ($proxyTepercaya === '')
            <div style="margin-top:10px; padding:10px 12px; border-radius:8px; background:rgba(239,68,68,.1); font-size:13px">
                Jalankan installer / patch terbaru (mengisi otomatis), atau isi manual di <code>C:\BillingPS\app\.env</code>:
                <code>TRUSTED_PROXIES=127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16</code>, lalu Pemeliharaan sistem → <b>Optimalkan</b>.
            </div>
        @endif

        <div style="margin-top:14px">
            <label for="isian-token" style="font-size:14px; font-weight:600">Token / perintah dari Cloudflare</label>
            <textarea id="isian-token" wire:model="isian" rows="3" autocomplete="off" spellcheck="false"
                      placeholder="cloudflared.exe service install eyJhIjoi…"
                      style="width:100%; margin-top:6px; padding:8px 10px; border-radius:8px; border:1px solid rgba(127,127,127,.35); background:transparent; font-family:monospace; font-size:12px"></textarea>
            <div style="font-size:12px; {{ $redup }} margin-top:4px">
                Tempel utuh perintah yang ditampilkan Cloudflare — token diambil otomatis. Tidak perlu menjalankan perintah itu di Windows.
                {{ $adaToken ? 'Kosongkan untuk menyalakan lagi dengan token tersimpan.' : '' }}
            </div>
        </div>

        <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:12px;">
            <x-filament::button icon="heroicon-o-play" wire:click="aktifkan" wire:loading.attr="disabled">
                {{ $status === 'running' ? 'Simpan & mulai ulang' : 'Aktifkan tunnel' }}
            </x-filament::button>
            @if ($status === 'running')
                <x-filament::button color="gray" icon="heroicon-o-stop" wire:click="matikan"
                                    wire:confirm="Matikan tunnel? Aplikasi tidak bisa dibuka dari internet sampai diaktifkan lagi.">Matikan</x-filament::button>
            @endif
            @if ($adaToken)
                <x-filament::button color="danger" icon="heroicon-o-trash" wire:click="hapusToken"
                                    wire:confirm="Matikan tunnel dan hapus token dari PC ini?">Hapus token</x-filament::button>
            @endif
        </div>
    </x-filament::section>

    <x-filament::section collapsible :collapsed="$adaToken">
        <x-slot name="heading">Cara membuat tunnel (sekali saja)</x-slot>
        <ol style="font-size:14px; line-height:1.7; padding-left:18px; list-style:decimal">
            <li>Domain sudah memakai Cloudflare (nameserver Cloudflare). Buka <b>one.dash.cloudflare.com</b> → <b>Networks → Tunnels</b> → <b>Create a tunnel</b>.</li>
            <li>Pilih <b>Cloudflared</b>, beri nama (mis. <i>rental-pusat</i>) → <b>Save tunnel</b>.</li>
            <li>Di "Install and run a connector" pilih <b>Windows</b>, salin perintah yang muncul (berisi <code>eyJ…</code>),
                tempel ke kotak di atas → <b>Aktifkan tunnel</b>. Tunggu status di Cloudflare menjadi <b>Healthy</b>.</li>
            <li>Lanjut ke <b>Public Hostname</b> (Route traffic): subdomain mis. <i>kasir</i>, domain Anda,
                Service <b>HTTP</b> → URL <code>{{ str_replace('http://', '', $layananLokal) }}</code> → Save.</li>
            <li>Buka <code>https://kasir.domainanda.com</code> — login dengan akun biasa.
                Batas login dari internet tetap berlaku (3x salah → diblokir 15 menit per IP).</li>
        </ol>
        <div style="font-size:13px; margin-top:8px">
            <b>Login gagal / tombol tidak bereaksi lewat domain?</b> Cek: (1) baris "Proxy tepercaya" di atas tidak merah;
            (2) Cloudflare → Speed → Optimization → <b>Rocket Loader: Off</b> (merusak JavaScript aplikasi);
            (3) Security → Bots → <b>Bot Fight Mode: Off</b> (menantang permintaan aplikasi); (4) Service memakai <b>HTTP</b>, bukan HTTPS.
        </div>
        <div style="font-size:12px; {{ $redup }} margin-top:6px">
            TV tetap memakai server lokal (LAN). Untuk keamanan tambahan, aktifkan <b>Cloudflare Access</b> (login email) pada hostname tersebut.
        </div>
    </x-filament::section>

    @if ($log)
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">Log tunnel terakhir</x-slot>
            <pre style="font-size:11px; white-space:pre-wrap; word-break:break-all; max-height:260px; overflow:auto">{{ implode("\n", $log) }}</pre>
        </x-filament::section>
    @endif
</x-filament-panels::page>
