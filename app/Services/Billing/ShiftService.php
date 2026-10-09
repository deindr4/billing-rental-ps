<?php

namespace App\Services\Billing;

use App\Exceptions\BillingException;
use App\Models\Cabang;
use App\Models\KasMutasi;
use App\Models\Pembayaran;
use App\Models\Pengaturan;
use App\Models\Sesi;
use App\Models\Shift;
use App\Models\Transaksi;
use App\Models\Unit;
use App\Models\User;
use App\Services\PinService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Shift kas = sesi satu laci kasir di sebuah cabang. Hanya SATU shift terbuka per cabang.
 * Pemegang (shift.user_id) bertanggung jawab atas laci; owner/supervisor (izin shift.bantu) boleh ikut bertransaksi.
 * Ganti kasir lewat serah terima: shift lama ditutup (kas dihitung, sebagian disetor, modal ditinggal)
 * dan shift penerima langsung dibuka dengan kas awal = uang yang ditinggal (dikonfirmasi PIN penerima).
 */
final class ShiftService
{
    /** Bawaan modal kembalian yang ditinggal di laci saat serah terima / tutup (Pengaturan Operasional) */
    public const MODAL_TETAP_DEFAULT = 200_000;

    public function __construct(
        private NomorTransaksi $nomor,
        private KasService $kas,
    ) {}

    /** Shift (laci) yang sedang terbuka di cabang, siapa pun pemegangnya */
    public function terbukaDiCabang(string $cabangId): ?Shift
    {
        return Shift::withoutGlobalScopes()
            ->where('cabang_id', $cabangId)
            ->where('status', Shift::STATUS_BUKA)
            ->latest('dibuka_pada')
            ->first();
    }

    /** Shift yang boleh dipakai user ini: miliknya sendiri, atau shift cabang bila ia boleh membantu (shift.bantu) */
    public function aktif(User $user, string $cabangId): ?Shift
    {
        $shift = $this->terbukaDiCabang($cabangId);

        if (! $shift) {
            return null;
        }

        return $shift->user_id === $user->id || $user->can('shift.bantu') ? $shift : null;
    }

    public function wajibAktif(User $user, string $cabangId): Shift
    {
        if ($shift = $this->aktif($user, $cabangId)) {
            return $shift;
        }

        $lain = $this->terbukaDiCabang($cabangId);

        throw new BillingException($lain
            ? 'Laci sedang dipegang '.($lain->user?->name ?? 'kasir lain').'. Minta serah terima shift ke Anda.'
            : 'Buka shift dan isi kas awal terlebih dahulu.');
    }

    public static function modalTetap(?string $cabangId): int
    {
        return max(0, (int) Pengaturan::ambil('kas.modal_tetap', self::MODAL_TETAP_DEFAULT, $cabangId));
    }

    public function buka(User $user, Cabang $cabang, int $kasAwal, ?Shift $sebelum = null, ?int $selisihTerima = null): Shift
    {
        if ($kasAwal < 0) {
            throw new BillingException('Kas awal tidak boleh negatif.');
        }

        return DB::transaction(function () use ($user, $cabang, $kasAwal, $sebelum, $selisihTerima) {
            // Satu laci per cabang: kunci baris cabang supaya dua kasir tidak membuka bersamaan
            Cabang::withoutGlobalScopes()->whereKey($cabang->id)->lockForUpdate()->first();

            if ($lain = $this->terbukaDiCabang($cabang->id)) {
                throw new BillingException($lain->user_id === $user->id
                    ? 'Masih ada shift yang terbuka. Tutup shift sebelumnya dulu.'
                    : 'Laci sedang dipegang '.($lain->user?->name ?? 'kasir lain').' sejak '.$lain->dibuka_pada->format('H:i').'. Minta serah terima shift.');
            }

            $shift = Shift::create([
                'tenant_id' => $cabang->tenant_id,
                'cabang_id' => $cabang->id,
                'user_id' => $user->id,
                'nomor' => $this->nomor->buat('SFT', $cabang),
                'status' => Shift::STATUS_BUKA,
                'dibuka_pada' => now(),
                'kas_awal' => $kasAwal,
                'shift_sebelum_id' => $sebelum?->id,
                'selisih_terima' => $selisihTerima,
            ]);

            $this->kas->catat($shift, 'kas_awal', $kasAwal, $user, $shift, $sebelum ? "Serah terima dari {$sebelum->nomor}" : 'Kas awal shift');

            return $shift;
        });
    }

    /**
     * Tutup shift (akhir hari / tanpa penerus). Uang yang ditinggal untuk besok dicatat; sisanya = setoran.
     *
     * @param  array<string,int>|null  $pecahan  contoh ['100000' => 2, '50000' => 1]
     */
    public function tutup(Shift $shift, User $user, int $kasFisik, ?string $catatan = null, ?array $pecahan = null, ?int $ditinggal = null): Shift
    {
        return DB::transaction(fn () => $this->tutupShift($shift, $user, $kasFisik, $catatan, $pecahan, $ditinggal ?? 0));
    }

    /**
     * Ganti kasir: shift lama ditutup & shift penerima dibuka dalam satu transaksi.
     * - $ditinggal: modal kembalian yang ditinggal di laci (bawaan = modal tetap cabang), sisanya disetor
     * - $dihitungPenerima: hitungan ulang penerima (null = menerima jumlah yang ditinggal); beda = selisih serah terima
     *
     * @return array{lama: Shift, baru: Shift}
     */
    public function serahTerima(
        Shift $shift,
        User $penyerah,
        User $penerima,
        ?string $pinPenerima,
        int $kasFisik,
        int $ditinggal,
        ?int $dihitungPenerima = null,
        ?string $catatan = null,
        ?array $pecahan = null,
    ): array {
        $penerima = User::query()->findOrFail($penerima->id); // status aktif & PIN terbaru

        if ($penerima->id === $shift->user_id) {
            throw new BillingException('Penerima sama dengan pemegang shift sekarang.');
        }

        if ($shift->user_id !== $penyerah->id && ! $penyerah->can('shift.bantu')) {
            throw new BillingException('Hanya pemegang shift (atau supervisor/owner) yang bisa menyerahkan laci.');
        }

        if (! $penerima->is_active || ! $penerima->cabangTersedia()->whereKey($shift->cabang_id)->exists()) {
            throw new BillingException("{$penerima->name} tidak bertugas di cabang ini.");
        }

        if ($ditinggal < 0 || $ditinggal > $kasFisik) {
            throw new BillingException('Uang yang ditinggal harus antara Rp0 dan kas fisik.');
        }

        $selisihTerima = $dihitungPenerima === null ? 0 : $dihitungPenerima - $ditinggal;

        if ($selisihTerima !== 0 && blank($catatan)) {
            throw new BillingException('Hitungan penerima berbeda dengan uang yang ditinggal. Isi keterangan.');
        }

        // PIN penerima: bukti ia menerima laci & uangnya
        app(PinService::class)->cocokkan($penerima, $pinPenerima, 'serah terima shift');

        return DB::transaction(function () use ($shift, $penyerah, $penerima, $kasFisik, $ditinggal, $dihitungPenerima, $selisihTerima, $catatan, $pecahan) {
            $lama = $this->tutupShift($shift, $penyerah, $kasFisik, $catatan, $pecahan, $ditinggal, $penerima);
            $cabang = Cabang::withoutGlobalScopes()->findOrFail($lama->cabang_id);
            $baru = $this->buka($penerima, $cabang, $dihitungPenerima ?? $ditinggal, $lama, $selisihTerima ?: null);

            $lama->update(['shift_berikut_id' => $baru->id]);

            Audit::catat('serah_terima_shift', "Serah terima {$lama->nomor} → {$baru->nomor}: {$penyerah->name} ke {$penerima->name}, ditinggal Rp "
                .number_format($ditinggal, 0, ',', '.').', setor Rp '.number_format($kasFisik - $ditinggal, 0, ',', '.'), $lama, [
                    'penerima' => $penerima->name, 'ditinggal' => $ditinggal, 'setoran' => $kasFisik - $ditinggal, 'selisih_terima' => $selisihTerima,
                ], userId: $penyerah->id);

            if ($selisihTerima !== 0) {
                Audit::catat('selisih_serah_terima', "Selisih serah terima {$lama->nomor}: penerima menghitung Rp "
                    .number_format((int) $dihitungPenerima, 0, ',', '.').', ditinggal Rp '.number_format($ditinggal, 0, ',', '.')." ({$catatan})", $baru, [
                        'ditinggal' => $ditinggal, 'dihitung' => $dihitungPenerima, 'selisih' => $selisihTerima,
                    ], anomali: true, userId: $penerima->id);
            }

            return ['lama' => $lama->refresh(), 'baru' => $baru];
        });
    }

    private function tutupShift(Shift $shift, User $user, int $kasFisik, ?string $catatan, ?array $pecahan, int $ditinggal, ?User $penerima = null): Shift
    {
        $shift = Shift::withoutGlobalScopes()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

        if (! $shift->isBuka()) {
            throw new BillingException('Shift ini sudah ditutup.');
        }

        if ($kasFisik < 0) {
            throw new BillingException('Kas fisik tidak boleh negatif.');
        }

        $ditinggal = max(0, min($ditinggal, $kasFisik));
        $seharusnya = $this->kas->saldo($shift);
        $selisih = $kasFisik - $seharusnya;

        if ($selisih !== 0 && blank($catatan)) {
            throw new BillingException('Ada selisih kas. Isi keterangan terlebih dahulu.');
        }

        $shift->update([
            'status' => Shift::STATUS_TUTUP,
            'ditutup_pada' => now(),
            'kas_seharusnya' => $seharusnya,
            'kas_fisik' => $kasFisik,
            'selisih' => $selisih,
            'kas_ditinggal' => $ditinggal,
            'setoran' => $kasFisik - $ditinggal,
            'rincian_pecahan' => $pecahan,
            'catatan_tutup' => $catatan,
            'ditutup_oleh' => $user->id,
            'diserahkan_ke' => $penerima?->id,
            // Potret yang masih berjalan saat laci ditinggalkan (diteruskan ke kasir berikutnya)
            'serah_terima' => $this->potretBerjalan($shift->cabang_id),
        ]);

        if ($selisih !== 0) {
            Audit::catat('selisih_kas', "Selisih kas shift {$shift->nomor}: Rp ".number_format($selisih, 0, ',', '.')." ({$catatan})", $shift, [
                'seharusnya' => $seharusnya, 'fisik' => $kasFisik, 'selisih' => $selisih,
            ], userId: $shift->user_id);
        }

        return $shift;
    }

    /** Sesi masih main & tagihan belum dibayar di cabang (diteruskan ke shift berikutnya) */
    public function potretBerjalan(string $cabangId): array
    {
        $sesi = Sesi::withoutGlobalScopes()->where('cabang_id', $cabangId)->aktif()
            ->with(['unit:id,kode,nama', 'transaksi:id,nomor,total,pelanggan_nama'])->get()
            ->map(fn (Sesi $s) => [
                'unit' => $s->unit?->kode, 'nomor' => $s->transaksi?->nomor, 'pelanggan' => $s->transaksi?->pelanggan_nama,
                'mode' => $s->mode, 'selesai' => $s->berakhir_pada?->format('H:i'), 'tagihan' => (int) $s->transaksi?->total,
                'sisa' => (int) $s->transaksi?->sisaTagihan(), // 0 = sudah dibayar di muka
            ])->values()->all();

        $belumBayar = Transaksi::withoutGlobalScopes()->where('cabang_id', $cabangId)
            ->where('status', Transaksi::STATUS_BELUM_BAYAR)
            ->whereDoesntHave('sesi', fn ($q) => $q->whereIn('status', [Sesi::STATUS_BERJALAN, Sesi::STATUS_DIJEDA]))
            ->with('unit:id,kode')->get()
            ->filter(fn (Transaksi $t) => $t->sisaTagihan() > 0)
            ->map(fn (Transaksi $t) => [
                'unit' => $t->unit?->kode ?? 'POS', 'nomor' => $t->nomor, 'pelanggan' => $t->pelanggan_nama, 'sisa' => $t->sisaTagihan(),
            ])->values()->all();

        return ['sesi_main' => $sesi, 'belum_bayar' => $belumBayar];
    }

    /** Ringkasan kas & pembayaran satu shift (tutup kas, serah terima, laporan) */
    public function ringkasan(Shift $shift): array
    {
        $mutasi = KasMutasi::withoutGlobalScopes()->where('shift_id', $shift->id)
            ->selectRaw('jenis, SUM(jumlah) as total')->groupBy('jenis')->pluck('total', 'jenis');

        $perMetode = Pembayaran::withoutGlobalScopes()->where('shift_id', $shift->id)->where('status', 'sukses')
            ->selectRaw('metode, SUM(jumlah) as total, COUNT(*) as jumlah')->groupBy('metode')->get()->keyBy('metode');

        return [
            'kas_awal' => (int) ($mutasi['kas_awal'] ?? 0),
            'penjualan_tunai' => (int) ($mutasi['penjualan'] ?? 0),
            'pembatalan' => (int) ($mutasi['pembatalan'] ?? 0),
            'topup_tunai' => (int) ($mutasi['topup'] ?? 0),
            'modal' => (int) ($mutasi['modal'] ?? 0),
            'prive' => (int) ($mutasi['prive'] ?? 0),
            'pengeluaran' => (int) ($mutasi['pengeluaran'] ?? 0),
            // Deposit sewa Playbox: titipan penyewa di laci (masuk saat sewa, keluar saat kembali), bukan omzet
            'deposit' => (int) ($mutasi['deposit_masuk'] ?? 0) + (int) ($mutasi['deposit_keluar'] ?? 0),
            'seharusnya' => (int) $mutasi->sum(),
            'qris' => (int) ($perMetode['qris']->total ?? 0),
            'transfer' => (int) ($perMetode['transfer']->total ?? 0),
            'saldo' => (int) ($perMetode['saldo']->total ?? 0),
            'qris_gateway' => (int) ($perMetode['qris_gateway']->total ?? 0),
            'jumlah_transaksi' => Pembayaran::withoutGlobalScopes()->where('shift_id', $shift->id)->where('status', 'sukses')
                ->distinct()->count('transaksi_id'),
            'jumlah_batal' => Transaksi::withoutGlobalScopes()->where('shift_id', $shift->id)
                ->where('status', Transaksi::STATUS_DIBATALKAN)->count(),
            'sesi_aktif' => Sesi::withoutGlobalScopes()->where('cabang_id', $shift->cabang_id)->aktif()->count(),
            'menunggu_bayar' => Unit::withoutGlobalScopes()->where('cabang_id', $shift->cabang_id)->where('status', Unit::STATUS_MENUNGGU_BAYAR)->count(),
        ];
    }
}
