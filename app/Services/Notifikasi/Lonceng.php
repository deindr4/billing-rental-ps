<?php

namespace App\Services\Notifikasi;

use App\Jobs\KirimNotifikasi;
use App\Models\AuditLog;
use App\Models\Cabang;
use App\Models\Notifikasi;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lonceng notifikasi di pojok kanan atas (kasir & admin).
 *
 * Lonceng::kirim('playbox_telat', 'Playbox BOX-01 telat', 'Kadek · telat 3 jam', url: route('playbox'), subjek: $sewa,
 *     kunci: "playbox_telat:{$sewa->id}:".today()->toDateString());
 *
 * - Penerima ditentukan izin (role) per jenis; pelaku tidak diberi tahu kejadiannya sendiri.
 * - `kunci` mencegah notifikasi ganda (juga antar server lewat sinkron).
 * - Tingkat "penting" diteruskan ke Telegram / WhatsApp cabang (bisa dipilih per jenis di Admin → Notifikasi & Laporan).
 * - Gagal mencatat tidak boleh menggagalkan transaksi utama.
 */
final class Lonceng
{
    /** Notifikasi lebih lama dari ini tidak tampil & dihapus `db:rapikan` */
    public const SIMPAN_HARI = 90;

    public const TINGKAT = [
        'penting' => 'Penting',
        'peringatan' => 'Peringatan',
        'info' => 'Info',
    ];

    /** kelompok => [label, izin bawaan, ikon, rute halaman terkait] */
    public const KELOMPOK = [
        'playbox' => ['label' => 'Sewa Playbox', 'izin' => 'playbox.kelola', 'ikon' => 'playbox', 'rute' => 'playbox'],
        'pembatalan' => ['label' => 'Pembatalan & koreksi', 'izin' => 'transaksi.batal', 'ikon' => 'transaksi', 'rute' => 'transaksi'],
        'booking' => ['label' => 'Booking', 'izin' => 'rental.kelola', 'ikon' => 'jadwal', 'rute' => 'jadwal'],
        'kas' => ['label' => 'Uang & kas', 'izin' => 'laporan.lihat', 'ikon' => 'kas', 'rute' => 'laporan'],
        'stok' => ['label' => 'Stok', 'izin' => 'stok.lihat', 'ikon' => 'stok', 'rute' => 'stok'],
        'keamanan' => ['label' => 'Keamanan', 'izin' => 'audit.lihat', 'ikon' => 'gembok', 'rute' => 'filament.admin.resources.audit-log.index'],
        'sistem' => ['label' => 'Sistem', 'izin' => 'admin.pengaturan', 'ikon' => 'admin', 'rute' => 'filament.admin.pages.dashboard'],
        'operasional' => ['label' => 'Karyawan & unit', 'izin' => 'laporan.lihat', 'ikon' => 'maintenance', 'rute' => 'rental'],
    ];

    /** jenis => [kelompok, tingkat, label, izin (opsional, menimpa izin kelompok), rute (opsional)] */
    public const JENIS = [
        // Sewa Playbox
        'playbox_baru' => ['playbox', 'info', 'Sewa Playbox baru'],
        'playbox_perpanjang' => ['playbox', 'info', 'Sewa Playbox diperpanjang'],
        'playbox_kembali' => ['playbox', 'info', 'Playbox dikembalikan'],
        'playbox_kembali_denda' => ['playbox', 'peringatan', 'Playbox kembali dengan denda / kerusakan'],
        'playbox_batal' => ['playbox', 'peringatan', 'Sewa Playbox dibatalkan'],
        'playbox_jatuh_tempo' => ['playbox', 'peringatan', 'Sewa Playbox segera jatuh tempo'],
        'playbox_telat' => ['playbox', 'penting', 'Sewa Playbox telat belum kembali'],
        'playbox_daftar_hitam' => ['playbox', 'penting', 'Playbox disewakan ke penyewa daftar hitam'],
        // Pembatalan & koreksi
        'batal_transaksi' => ['pembatalan', 'penting', 'Transaksi dibatalkan'],
        'batal_fnb' => ['pembatalan', 'peringatan', 'Pesanan F&B dibatalkan'],
        'batal_tambah_waktu' => ['pembatalan', 'peringatan', 'Tambah waktu dibatalkan'],
        'batal_aksesori' => ['pembatalan', 'peringatan', 'Sewa aksesori dibatalkan'],
        'batal_pengeluaran' => ['pembatalan', 'peringatan', 'Pengeluaran dibatalkan', 'pengeluaran.batal', 'pengeluaran'],
        'batal_gaji' => ['pembatalan', 'peringatan', 'Pembayaran gaji dibatalkan', 'karyawan.kelola', 'filament.admin.pages.dashboard'],
        'waktu_gratis' => ['pembatalan', 'peringatan', 'Waktu gratis diberikan', 'sesi.gratis'],
        'koreksi_member' => ['pembatalan', 'peringatan', 'Saldo / poin member dikoreksi', 'member.koreksi', 'member'],
        'koreksi_hpp' => ['pembatalan', 'info', 'HPP dikoreksi', 'laporan.laba', 'laporan'],
        // Booking
        'booking_baru' => ['booking', 'info', 'Booking online baru'],
        'booking_segera' => ['booking', 'info', 'Booking mulai 15 menit lagi'],
        'booking_tidak_datang' => ['booking', 'peringatan', 'Booking tidak datang'],
        // Uang & kas
        'selisih_kas' => ['kas', 'penting', 'Selisih kas saat tutup kas'],
        'selisih_serah_terima' => ['kas', 'penting', 'Selisih kas saat serah terima'],
        'bayar_mandiri_tindakan' => ['kas', 'peringatan', 'Bayar mandiri perlu tindakan', 'pembayaran.terima', 'pembayaran-online'],
        // Stok
        'stok_habis' => ['stok', 'peringatan', 'Stok habis'],
        'stok_menipis' => ['stok', 'info', 'Stok menipis'],
        // Keamanan
        'login_gagal' => ['keamanan', 'penting', 'Login gagal berulang'],
        'pin_gagal' => ['keamanan', 'peringatan', 'PIN salah berulang'],
        'bypass_tv' => ['keamanan', 'info', 'TV di-bypass'],
        'kode_darurat' => ['keamanan', 'peringatan', 'Kode darurat TV dipakai'],
        // Sistem
        'backup_terlambat' => ['sistem', 'penting', 'Backup otomatis tidak berjalan', null, 'filament.admin.pages.backup'],
        'pulihkan_backup' => ['sistem', 'penting', 'Database dipulihkan dari backup'],
        'sinkron_macet' => ['sistem', 'peringatan', 'Sinkronisasi cloud macet'],
        'antrean_macet' => ['sistem', 'peringatan', 'Antrean tugas tidak berjalan'],
        'disk_penuh' => ['sistem', 'peringatan', 'Ruang disk hampir penuh'],
        'versi_baru' => ['sistem', 'info', 'Versi baru aplikasi tersedia', null, 'filament.admin.pages.pemeliharaan'],
        // Karyawan & unit
        'tv_offline_sesi' => ['operasional', 'peringatan', 'TV / agen PC offline saat sesi berjalan', 'rental.kelola'],
        'shift_lama' => ['operasional', 'peringatan', 'Shift belum ditutup lebih dari 12 jam'],
        'unit_maintenance' => ['operasional', 'info', 'Unit masuk maintenance', 'maintenance.kelola', 'maintenance'],
    ];

    /** Aksi audit log yang langsung menjadi notifikasi (aksi => jenis) */
    private const DARI_AUDIT = [
        'batal_transaksi' => 'batal_transaksi', 'batal_fnb' => 'batal_fnb', 'batal_tambah_waktu' => 'batal_tambah_waktu',
        'batal_aksesori' => 'batal_aksesori', 'batal_pengeluaran' => 'batal_pengeluaran', 'batal_gaji' => 'batal_gaji',
        'waktu_gratis' => 'waktu_gratis', 'koreksi_member' => 'koreksi_member', 'koreksi_hpp' => 'koreksi_hpp',
        'selisih_kas' => 'selisih_kas', 'selisih_serah_terima' => 'selisih_serah_terima',
        'bypass_tv' => 'bypass_tv', 'kode_darurat' => 'kode_darurat', 'pulihkan_backup' => 'pulihkan_backup',
        'sewa_daftar_hitam' => 'playbox_daftar_hitam',
    ];

    /** Cache jenis yang boleh dilihat per pengguna (satu request) */
    private static array $jenisPengguna = [];

    public static function kirim(
        string $jenis,
        ?string $judul = null,
        ?string $isi = null,
        ?string $url = null,
        ?Model $subjek = null,
        ?string $kunci = null,
        ?string $tenantId = null,
        ?string $cabangId = null,
        string|false|null $userId = false, // pelaku; false = pengguna yang login, null = tanpa pelaku (jadwal / publik)
    ): ?Notifikasi {
        $def = self::JENIS[$jenis] ?? null;

        if (! $def) {
            return null;
        }

        try {
            $tenancy = app(Tenancy::class);
            $tenantId ??= $subjek?->getAttribute('tenant_id') ?? $tenancy->tenantId() ?? self::tenantTunggal();

            if (! $tenantId) {
                return null;
            }

            if ($kunci && Notifikasi::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('kunci', $kunci)->exists()) {
                return null;
            }

            $n = Notifikasi::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'cabang_id' => $cabangId ?? $subjek?->getAttribute('cabang_id') ?? $tenancy->cabangId(),
                'jenis' => $jenis,
                'kelompok' => $def[0],
                'tingkat' => $def[1],
                'judul' => Str::limit($judul ?? $def[2], 145),
                'isi' => $isi !== null ? Str::limit($isi, 495) : null,
                'url' => $url ?? self::urlBawaan($jenis),
                'kunci' => $kunci,
                'subjek_type' => $subjek?->getMorphClass(),
                'subjek_id' => $subjek?->getKey(),
                'user_id' => $userId === false ? auth()->id() : $userId,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($n->tingkat === 'penting') {
            self::teruskan($n);
        }

        return $n;
    }

    /** Dipanggil Audit::catat untuk setiap baris audit baru */
    public static function dariAudit(AuditLog $log): void
    {
        $jenis = self::DARI_AUDIT[$log->aksi] ?? null;
        $kunci = null;

        if ($log->aksi === 'bayar_mandiri' && $log->anomali) {
            $jenis = 'bayar_mandiri_tindakan';
        } elseif ($log->aksi === 'login_gagal') {
            // Satu-dua salah ketik wajar; 5x dalam 15 menit dari alamat yang sama patut dicurigai
            $n = AuditLog::withoutGlobalScopes()->where('aksi', 'login_gagal')->where('ip', $log->ip)
                ->where('created_at', '>=', now()->subMinutes(15))->count();
            [$jenis, $kunci] = $n >= 5 ? ['login_gagal', 'login_gagal:'.$log->ip.':'.intdiv(time(), 900)] : [null, null];
        } elseif ($log->aksi === 'pin_gagal') {
            $n = AuditLog::withoutGlobalScopes()->where('aksi', 'pin_gagal')->where('user_id', $log->user_id)
                ->where('created_at', '>=', now()->subMinutes(10))->count();
            [$jenis, $kunci] = $n >= 3 ? ['pin_gagal', 'pin_gagal:'.$log->user_id.':'.intdiv(time(), 600)] : [null, null];
        }

        if (! $jenis) {
            return;
        }

        $pelaku = $log->user_id ? User::withoutGlobalScopes()->whereKey($log->user_id)->value('name') : null;
        $isi = $log->keterangan.($pelaku ? " · oleh {$pelaku}" : '').($log->aksi === 'login_gagal' && $log->ip ? " · IP {$log->ip}" : '');

        self::kirim($jenis, isi: $isi, kunci: $kunci, tenantId: $log->tenant_id, cabangId: $log->cabang_id,
            // Login / PIN gagal: pelakunya justru perlu tahu (akun bisa sedang dicoba orang lain)
            userId: in_array($jenis, ['login_gagal', 'pin_gagal'], true) ? null : $log->user_id);
    }

    /* ---------------- Penerima & status baca ---------------- */

    /** Jenis notifikasi yang boleh dilihat pengguna (berdasarkan izin role) */
    public static function jenisUntuk(User $u): array
    {
        return self::$jenisPengguna[$u->id] ??= array_keys(array_filter(self::JENIS,
            fn ($d) => $u->can($d[3] ?? self::KELOMPOK[$d[0]]['izin'])));
    }

    /** Notifikasi yang tampil untuk pengguna; $cabangId null = semua cabang miliknya (panel admin) */
    public static function untuk(User $u, ?string $cabangId = null): Builder
    {
        $cabang = $cabangId ? [$cabangId] : $u->cabang()->pluck('cabang.id')->all();

        return Notifikasi::withoutGlobalScopes()
            ->where('tenant_id', $u->tenant_id)
            ->whereIn('jenis', self::jenisUntuk($u) ?: ['-'])
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $u->id))
            ->where(fn ($q) => $q->whereNull('cabang_id')->orWhereIn('cabang_id', $cabang))
            ->where('created_at', '>=', now()->subDays(self::SIMPAN_HARI))
            ->latest('created_at');
    }

    public static function belumDibaca(Builder $q, User $u): Builder
    {
        $sampai = DB::table('notifikasi_pengguna')->where('user_id', $u->id)->value('dibaca_sampai');

        return $q->when($sampai, fn ($q) => $q->where('created_at', '>', $sampai))
            ->whereNotExists(fn ($s) => $s->from('notifikasi_baca')->whereColumn('notifikasi_baca.notifikasi_id', 'notifikasi.id')
                ->where('notifikasi_baca.user_id', $u->id));
    }

    public static function jumlahBelumDibaca(User $u, ?string $cabangId = null): int
    {
        return self::belumDibaca(self::untuk($u, $cabangId), $u)->limit(100)->count();
    }

    /** id notifikasi (dari daftar) yang sudah dibaca pengguna */
    public static function idDibaca(User $u, array $ids): array
    {
        $sampai = DB::table('notifikasi_pengguna')->where('user_id', $u->id)->value('dibaca_sampai');
        $baca = DB::table('notifikasi_baca')->where('user_id', $u->id)->whereIn('notifikasi_id', $ids)->pluck('notifikasi_id')->all();

        if ($sampai) {
            $baca = array_merge($baca, Notifikasi::withoutGlobalScopes()->whereIn('id', $ids)->where('created_at', '<=', $sampai)->pluck('id')->all());
        }

        return array_values(array_unique($baca));
    }

    public static function tandaiDibaca(User $u, string $id): void
    {
        DB::table('notifikasi_baca')->insertOrIgnore(['user_id' => $u->id, 'notifikasi_id' => $id, 'dibaca_pada' => now()]);
    }

    public static function tandaiSemua(User $u): void
    {
        DB::table('notifikasi_pengguna')->updateOrInsert(['user_id' => $u->id], ['dibaca_sampai' => now()]);
        DB::table('notifikasi_baca')->where('user_id', $u->id)->delete();
    }

    /* ---------------- Teruskan ke Telegram / WhatsApp ---------------- */

    /** Jenis tingkat penting (bawaan semuanya diteruskan) */
    public static function jenisPenting(): array
    {
        return array_keys(array_filter(self::JENIS, fn ($d) => $d[1] === 'penting'));
    }

    private static function teruskan(Notifikasi $n): void
    {
        try {
            $cabangId = $n->cabang_id ?? Cabang::withoutGlobalScopes()->where('tenant_id', $n->tenant_id)->orderBy('kode')->value('id');

            if (! $cabangId) {
                return;
            }

            $setelan = PengaturanNotifikasi::untuk($cabangId);

            if (! in_array($n->jenis, $setelan->loncengTeruskan(), true)) {
                return;
            }

            $cabang = Cabang::withoutGlobalScopes()->whereKey($cabangId)->value('nama');
            $teks = "🔴 PENTING · {$n->judul}\n".($n->isi ? "{$n->isi}\n" : '')
                .'📍 '.($cabang ?? config('app.name')).' · '.$n->created_at->format('d/m/Y H:i');

            foreach (['telegram' => $setelan->telegramAktif(), 'whatsapp' => $setelan->waAktif()] as $saluran => $aktif) {
                if ($aktif) {
                    KirimNotifikasi::antrekan($saluran, $n->tenant_id, $cabangId, 'lonceng', $teks, referensi: $n);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /* ---------------- Bantuan ---------------- */

    public static function label(string $jenis): string
    {
        return self::JENIS[$jenis][2] ?? $jenis;
    }

    public static function ikon(string $kelompok): string
    {
        return self::KELOMPOK[$kelompok]['ikon'] ?? 'lonceng';
    }

    private static function urlBawaan(string $jenis): ?string
    {
        $rute = self::JENIS[$jenis][4] ?? self::KELOMPOK[self::JENIS[$jenis][0]]['rute'];

        return Route::has($rute) ? route($rute, absolute: false) : null;
    }

    /** Server lokal biasanya hanya punya satu rental: kejadian tanpa konteks tenant (mis. login gagal) masuk ke sana */
    private static function tenantTunggal(): ?string
    {
        return Tenant::query()->limit(2)->pluck('id')->pipe(fn ($c) => $c->count() === 1 ? $c->first() : null);
    }
}
