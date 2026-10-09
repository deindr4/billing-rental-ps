<?php

namespace App\Services\Notifikasi;

use App\Models\Booking;
use App\Models\PerangkatTv;
use App\Models\Sesi;
use App\Models\SewaPlaybox;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\BackupService;
use App\Services\Sinkron\SinkronService;
use App\Services\UpdateAplikasi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Pemeriksaan berkala untuk lonceng (jadwal tiap 5 menit, hanya server lokal supaya tidak dobel dengan cloud):
 * Playbox jatuh tempo / telat, booking segera mulai, stok menipis, shift lupa ditutup, TV offline saat sesi, kesehatan sistem.
 * Setiap notifikasi memakai `kunci` sehingga aman dijalankan berulang (telat Playbox diulang sekali per hari).
 */
final class PeriksaLonceng
{
    /** Shift dianggap lupa ditutup setelah sekian jam */
    public const SHIFT_JAM = 12;

    /** TV dianggap bermasalah bila tidak ada kontak selama sesi berjalan sekian menit */
    public const TV_MENIT = 5;

    public function jalankan(): int
    {
        $n = 0;

        foreach (['playbox', 'booking', 'stok', 'shift', 'tv', 'sistem'] as $cek) {
            try {
                $n += $this->{$cek}();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $n;
    }

    private function playbox(): int
    {
        $n = 0;
        $hariIni = today()->toDateString();

        $sewa = SewaPlaybox::withoutGlobalScopes()->with(['playbox:id,kode', 'penyewa:id,nama,telepon'])
            ->where('status', 'berjalan')->where('jatuh_tempo', '<=', now()->addHours(3))->get();

        foreach ($sewa as $s) {
            $kode = $s->playbox?->kode ?? $s->nomor;
            $siapa = $s->penyewa?->nama.($s->penyewa?->telepon ? " ({$s->penyewa->telepon})" : '');

            $hasil = $s->jatuh_tempo->isPast()
                ? Lonceng::kirim('playbox_telat', "{$kode} telat · {$s->penyewa?->nama}",
                    "{$siapa} · jatuh tempo ".$s->jatuh_tempo->format('d/m H:i').' ('.$s->jatuh_tempo->diffForHumans().') · '.$s->nomor,
                    subjek: $s, kunci: "playbox_telat:{$s->id}:{$hariIni}", userId: null)
                : Lonceng::kirim('playbox_jatuh_tempo', "{$kode} jatuh tempo ".$s->jatuh_tempo->format('H:i'),
                    "{$siapa} · ".$s->jatuh_tempo->diffForHumans().' · '.$s->nomor,
                    subjek: $s, kunci: "playbox_jatuh_tempo:{$s->id}:".$s->jatuh_tempo->timestamp, userId: null);
            $n += $hasil ? 1 : 0;
        }

        return $n;
    }

    private function booking(): int
    {
        $n = 0;

        $booking = Booking::withoutGlobalScopes()->with('unit:id,nama')->aktif()
            ->whereBetween('mulai_pada', [now(), now()->addMinutes(15)])->get();

        foreach ($booking as $b) {
            $n += Lonceng::kirim('booking_segera', "Booking {$b->kode} · {$b->nama} mulai ".$b->mulai_pada->format('H:i'),
                ($b->unit?->nama ?? '-')." · {$b->durasi_menit} menit · {$b->telepon}"
                .($b->status === 'menunggu' ? ' · BELUM dikonfirmasi' : ''),
                subjek: $b, kunci: "booking_segera:{$b->id}", userId: null) ? 1 : 0;
        }

        return $n;
    }

    /** Stok F&B per cabang di bawah / sama dengan stok minimum (sekali per produk per hari) */
    private function stok(): int
    {
        $n = 0;
        $hariIni = today()->toDateString();

        $baris = DB::table('produk_stok')
            ->join('produk', 'produk.id', '=', 'produk_stok.produk_id')
            ->where('produk.lacak_stok', true)->where('produk.is_active', true)
            ->where(fn ($q) => $q->where('produk_stok.qty', '<=', 0)->orWhereColumn('produk_stok.qty', '<=', 'produk.stok_minimum'))
            ->get(['produk_stok.tenant_id', 'produk_stok.cabang_id', 'produk_stok.produk_id', 'produk_stok.qty', 'produk.nama', 'produk.stok_minimum', 'produk.satuan']);

        foreach ($baris as $b) {
            $habis = $b->qty <= 0;
            $n += Lonceng::kirim($habis ? 'stok_habis' : 'stok_menipis', ($habis ? 'Stok habis: ' : 'Stok menipis: ').$b->nama,
                "Sisa {$b->qty} {$b->satuan} · minimum {$b->stok_minimum}",
                kunci: ($habis ? 'stok_habis' : 'stok_menipis').":{$b->produk_id}:{$b->cabang_id}:{$hariIni}",
                tenantId: $b->tenant_id, cabangId: $b->cabang_id, userId: null) ? 1 : 0;
        }

        return $n;
    }

    private function shift(): int
    {
        $n = 0;

        $shift = Shift::withoutGlobalScopes()->with('user:id,name')->where('status', Shift::STATUS_BUKA)
            ->where('dibuka_pada', '<', now()->subHours(self::SHIFT_JAM))->get();

        foreach ($shift as $s) {
            $n += Lonceng::kirim('shift_lama', "Shift {$s->nomor} belum ditutup",
                'Dibuka '.$s->dibuka_pada->format('d/m H:i').' ('.$s->dibuka_pada->diffForHumans().') oleh '.($s->user?->name ?? '-')
                .' · tutup kas supaya laporan & laci benar',
                subjek: $s, kunci: "shift_lama:{$s->id}", userId: null) ? 1 : 0;
        }

        return $n;
    }

    /** Sesi berjalan tapi TV unitnya tidak ada kontak: pelanggan bisa main tanpa layar terkunci / timer */
    private function tv(): int
    {
        $n = 0;

        $sesi = Sesi::withoutGlobalScopes()->with('unit:id,nama')->where('status', Sesi::STATUS_BERJALAN)
            ->where('mulai_pada', '<', now()->subMinutes(self::TV_MENIT))->get(['id', 'tenant_id', 'cabang_id', 'unit_id', 'mulai_pada']);

        if ($sesi->isEmpty()) {
            return 0;
        }

        $tv = PerangkatTv::withoutGlobalScopes()->aktif()->whereIn('unit_id', $sesi->pluck('unit_id'))->get(['unit_id', 'jenis', 'terakhir_online'])
            ->keyBy('unit_id');

        foreach ($sesi as $s) {
            $t = $tv->get($s->unit_id);

            if (! $t || ($t->terakhir_online && $t->terakhir_online->gt(now()->subMinutes(self::TV_MENIT)))) {
                continue; // unit tanpa TV Agent, atau TV masih online
            }

            $alat = $t->jenis === 'pc' ? 'Agen PC' : 'TV';
            $n += Lonceng::kirim('tv_offline_sesi', ($s->unit?->nama ?? '').": {$alat} offline saat sesi berjalan",
                'Kontak terakhir '.($t->terakhir_online?->diffForHumans() ?? 'belum pernah')." · cek daya / jaringan {$alat}",
                subjek: $s, kunci: "tv_offline_sesi:{$s->id}", tenantId: $s->tenant_id, cabangId: $s->cabang_id, userId: null) ? 1 : 0;
        }

        return $n;
    }

    /** Kesehatan sistem per rental (tanpa cabang): backup, sinkron, antrean, disk, versi baru */
    private function sistem(): int
    {
        $tenant = Tenant::query()->pluck('id');
        $hariIni = today()->toDateString();
        $temuan = [];

        $backup = app(BackupService::class)->terakhir();
        // Pemasangan baru (< 36 jam) belum wajib punya backup
        $umurApp = DB::table('users')->min('created_at');

        if ((! $backup && $umurApp && Carbon::parse($umurApp)->lt(now()->subHours(36))) || ($backup && $backup->lt(now()->subHours(36)))) {
            $temuan[] = ['backup_terlambat', null, $backup ? 'Backup terakhir '.$backup->format('d/m/Y H:i').' ('.$backup->diffForHumans().')' : 'Belum pernah ada backup',
                'Pastikan layanan Jadwal (scheduler) berjalan · Admin → Backup → Buat backup', "backup_terlambat:{$hariIni}"];
        }

        $sinkron = app(SinkronService::class);

        if (! $sinkron->diCloud() && $sinkron->siap()) {
            $status = $sinkron->status();
            $ok = $status['terakhir_ok'] ? Carbon::parse($status['terakhir_ok']) : null;

            if ($status['error'] || ! $ok || $ok->lt(now()->subMinutes(30))) {
                $temuan[] = ['sinkron_macet', null, 'Terakhir berhasil '.($ok?->diffForHumans() ?? 'belum pernah'),
                    ($status['error'] ? $status['error'].' · ' : '').number_format($sinkron->jumlahAntrean(), 0, ',', '.').' perubahan menunggu',
                    'sinkron_macet:'.$hariIni.':'.intdiv(now()->hour, 6)];
            }
        }

        $tertua = Schema::hasTable('jobs') ? DB::table('jobs')->min('created_at') : null;

        if ($tertua && now()->timestamp - (int) $tertua > 600) {
            $temuan[] = ['antrean_macet', null, DB::table('jobs')->count().' tugas menunggu',
                'Notifikasi WA/Telegram & sinyal TV tertunda · nyalakan layanan Antrean (Delta Billing HuB Monitor)', "antrean_macet:{$hariIni}"];
        }

        $bebas = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());

        if ($bebas !== false && $total && ($bebas < 5 * 1024 ** 3 || $bebas / $total < 0.05)) {
            $temuan[] = ['disk_penuh', null, 'Sisa '.number_format($bebas / 1024 ** 3, 1, ',', '.').' GB dari '.number_format($total / 1024 ** 3, 0, ',', '.').' GB',
                'Hapus file lama / pindahkan backup ke flashdisk', "disk_penuh:{$hariIni}"];
        }

        $versi = app(UpdateAplikasi::class)->terakhir();

        if (is_array($versi) && ($versi['baru'] ?? false)) {
            $temuan[] = ['versi_baru', 'Versi baru '.$versi['versi'].' tersedia', 'Terpasang '.$versi['sekarang'].' · Pemeliharaan sistem → Cek update', null,
                'versi_baru:'.$versi['versi']];
        }

        $n = 0;

        foreach ($tenant as $t) {
            foreach ($temuan as [$jenis, $judul, $isi, $tambahan, $kunci]) {
                $n += Lonceng::kirim($jenis, $judul, trim($isi.($tambahan ? " · {$tambahan}" : '')), kunci: $kunci,
                    tenantId: $t, cabangId: null, userId: null) ? 1 : 0;
            }
        }

        return $n;
    }
}
