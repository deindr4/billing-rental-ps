<?php

namespace App\Services\Publik;

use App\Models\Booking;
use App\Models\Cabang;
use App\Models\Iklan;
use App\Models\PaketHarga;
use App\Models\Pengaturan;
use App\Models\Sesi;
use App\Models\Shift;
use App\Models\Turnamen;
use App\Models\Unit;
use App\Support\Tema;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Data halaman billboard publik (dibuka pelanggan di HP lewat link): status buka/tutup, jam operasional,
 * status stasiun live, booking hari ini, turnamen, pengumuman, iklan. Tenancy sudah diset ke cabang billboard sebelum dipanggil.
 */
final class BillboardService
{
    /** Kunci URL billboard per cabang (dibuat otomatis) */
    public static function kunci(Cabang $cabang): string
    {
        $row = Pengaturan::withoutGlobalScopes()->where('tenant_id', $cabang->tenant_id)
            ->where('cabang_id', $cabang->id)->where('kunci', 'billboard.kunci')->first();

        if ($row && ! empty($row->nilai['v'])) {
            return $row->nilai['v'];
        }

        return self::simpanKunci($cabang, Str::random(24));
    }

    private static function simpanKunci(Cabang $cabang, string $kunci): string
    {
        $row = Pengaturan::withoutGlobalScopes()->firstOrNew([
            'tenant_id' => $cabang->tenant_id, 'cabang_id' => $cabang->id, 'kunci' => 'billboard.kunci',
        ]);
        $row->nilai = ['v' => $kunci];
        $row->save();

        return $kunci;
    }

    public static function url(Cabang $cabang): string
    {
        return route('billboard', ['kode' => $cabang->kode, 'k' => self::kunci($cabang)]);
    }

    public static function gantiKunci(Cabang $cabang): void
    {
        self::simpanKunci($cabang, Str::random(24));
    }

    public function data(Cabang $cabang): array
    {
        $units = Unit::query()->aktif()->with(['tipeKonsol:id,kode,nama', 'kategori:id,nama'])->urut()->get();
        $sesi = Sesi::query()->aktif()->get()->keyBy('unit_id');
        $tarif = $this->tarifPerTipe($units);

        $booking = Booking::query()->aktif()
            ->whereBetween('mulai_pada', [now()->subMinutes(15), now()->endOfDay()])
            ->orderBy('mulai_pada')->get(['id', 'unit_id', 'nama', 'mulai_pada']);

        $matriks = $units->map(function (Unit $u) use ($sesi, $tarif, $booking) {
            $s = $sesi->get($u->id);
            $bk = $booking->firstWhere('unit_id', $u->id);

            $status = match (true) {
                $u->status === Unit::STATUS_SERVIS => 'servis',
                $s !== null && $s->status === Sesi::STATUS_DIJEDA => 'jeda',
                $s !== null && $s->berakhir_pada && $s->sisaDetik() !== null && $s->sisaDetik() <= 600 => 'hampir',
                $s !== null => 'main',
                $u->status === Unit::STATUS_MENUNGGU_BAYAR => 'bayar',
                default => 'kosong',
            };

            return [
                'id' => $u->id,
                'nama' => $u->nama,
                'kode' => $u->kode,
                'tipe' => $u->tipeKonsol?->kode,
                'kategori' => $u->kategori?->nama,
                'status' => $status,
                'berakhir_ms' => $s?->berakhir_pada?->getTimestampMs(),
                'mulai_ms' => $s && ! $s->berakhir_pada ? $s->mulai_pada?->getTimestampMs() : null,
                'tarif' => $tarif[$u->tipe_konsol_id.'|'.$u->kategori_unit_id] ?? null,
                'booking' => $bk ? $bk->mulai_pada->format('H:i') : null,
            ];
        });

        // BUKA jika ada kasir yang membuka kas di cabang ini
        $buka = Shift::withoutGlobalScopes()->where('cabang_id', $cabang->id)->where('status', Shift::STATUS_BUKA)->exists();

        return [
            'buka' => $buka,
            'jam' => BookingService::jamOperasionalCabang($cabang->id),
            'iklan' => Iklan::query()->tayang($cabang->id)->get(['id', 'judul', 'pengiklan', 'tautan', 'lebar', 'tinggi', 'updated_at'])
                ->map(fn (Iklan $i) => [
                    'id' => $i->id,
                    'judul' => $i->judul,
                    'tautan' => $i->tautan,
                    'gambar' => $i->urlGambar(),
                    'rasio' => $i->lebar && $i->tinggi ? $i->lebar.' / '.$i->tinggi : '2 / 1',
                ])->all(),
            'cabang' => $cabang->nama,
            'alamat' => $cabang->alamat,
            'telepon' => $cabang->telepon,
            'tenant' => $cabang->tenant?->nama ?? config('app.name'),
            'logo' => Tema::logoUrl(),
            'aksen' => Tema::aksen(),
            'pengumuman' => trim((string) (Pengaturan::ambil('billboard.pengumuman', null, $cabang->id)
                ?: Pengaturan::ambil('tv.pengumuman', '', $cabang->id))),
            'matriks' => $matriks->values()->all(),
            'terisi' => $matriks->whereIn('status', ['main', 'hampir', 'jeda'])->count(),
            'kosong' => $matriks->where('status', 'kosong')->count(),
            'total' => $matriks->where('status', '!=', 'servis')->count(),
            'booking' => $booking->take(6)->map(fn ($b) => [
                'jam' => $b->mulai_pada->format('H:i'),
                'nama' => $this->samarkan($b->nama),
                'unit' => $matriks->firstWhere('id', $b->unit_id)['nama'] ?? '-',
            ])->values()->all(),
            'turnamen' => $this->turnamen(),
            'booking_url' => Pengaturan::ambil('booking.aktif', false, $cabang->id) && Route::has('booking')
                ? route('booking', $cabang->kode) : null,
            'server_ms' => now()->getTimestampMs(),
        ];
    }

    /** Tarif per jam per kombinasi tipe konsol & kategori */
    private function tarifPerTipe($units): array
    {
        $hasil = [];

        foreach ($units as $u) {
            $k = $u->tipe_konsol_id.'|'.$u->kategori_unit_id;
            $hasil[$k] ??= PaketHarga::query()->untukUnit($u)->aktif()->where('jenis', PaketHarga::JENIS_PER_JAM)
                ->orderByRaw('cabang_id is null')->orderByRaw('kategori_unit_id is null')->orderByRaw('tipe_konsol_id is null')
                ->value('harga');
        }

        return $hasil;
    }

    /** Turnamen yang sedang berjalan (bagan) atau pendaftaran terdekat */
    private function turnamen(): ?array
    {
        $t = Turnamen::query()->whereIn('status', ['berjalan', 'pendaftaran'])
            ->orderByRaw("status = 'berjalan' desc")->orderBy('mulai_pada')->first();

        if (! $t) {
            return null;
        }

        $laga = fn ($p) => [
            'a' => $p->pesertaA?->nama,
            'b' => $p->pesertaB?->nama,
            'skor_a' => $p->skor_a,
            'skor_b' => $p->skor_b,
            'pemenang' => $p->pemenang?->nama,
            'status' => $p->status,
            'unit' => $p->unit?->nama,
        ];

        return [
            'nama' => $t->nama,
            'game' => $t->game,
            'status' => $t->status,
            'mulai' => $t->mulai_pada->translatedFormat('l, d M H:i'),
            'hadiah' => $t->hadiah,
            'biaya' => $t->biaya_daftar,
            'peserta' => $t->pesertaAktif()->count(),
            'kuota' => $t->kuota,
            'format' => $t->namaFormat(),
            'bonus' => $t->labelBonus(),
            'total_hadiah' => $t->total_hadiah,
            // Bagan / klasemen per bagian (semua format); laga liga/grup tidak ditampilkan di billboard, cukup klasemen
            'bagian' => collect(app(TurnamenService::class)->tampilan($t))->map(fn ($bg) => [
                'judul' => $bg['judul'],
                'klasemen' => $bg['jenis'] === 'klasemen'
                    ? array_map(fn ($r) => ['nama' => $r['peserta']->nama, 'main' => $r['main'], 'sg' => $r['sg'], 'poin' => $r['poin']], $bg['klasemen'])
                    : null,
                'lolos' => $bg['lolos'] ?? 0,
                'kolom' => $bg['jenis'] === 'bagan'
                    ? array_map(fn ($k) => ['nama' => $k['nama'], 'laga' => $k['laga']->map($laga)->all()], $bg['kolom'])
                    : [],
            ])->all(),
        ];
    }

    /** "Raka Permana" -> "Raka P." (billboard terlihat umum) */
    private function samarkan(string $nama): string
    {
        $kata = preg_split('/\s+/', trim($nama)) ?: [$nama];

        return $kata[0].(isset($kata[1]) ? ' '.mb_strtoupper(mb_substr($kata[1], 0, 1)).'.' : '');
    }
}
