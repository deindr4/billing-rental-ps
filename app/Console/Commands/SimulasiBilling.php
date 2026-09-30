<?php

namespace App\Console\Commands;

use App\Models\Cabang;
use App\Models\PaketHarga;
use App\Models\Sesi;
use App\Models\Transaksi;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\KasService;
use App\Services\Billing\ShiftService;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Uji alur billing tanpa UI.
 *   php artisan billing:simulasi           -> data di-rollback (database tetap bersih)
 *   php artisan billing:simulasi --simpan  -> data disimpan
 */
class SimulasiBilling extends Command
{
    protected $signature = 'billing:simulasi {--simpan : Simpan data hasil simulasi}';

    protected $description = 'Simulasi alur shift, sesi paket & open billing, bayar, batal, tutup kas';

    public function handle(BillingService $billing, ShiftService $shifts, KasService $kas, Tenancy $tenancy): int
    {
        $owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $cabang = Cabang::where('tenant_id', $owner->tenant_id)->where('kode', 'DGH1')->firstOrFail();
        $tenancy->set($owner->tenant_id, $cabang->id);

        $rp = fn (int $n): string => 'Rp '.number_format($n, 0, ',', '.');

        DB::beginTransaction();

        try {
            $this->info('1. Buka shift, kas awal Rp 200.000');
            $shift = $shifts->buka($owner, $cabang, 200000);
            $this->line("   Shift: {$shift->nomor}");

            $tv2 = Unit::where('kode', 'TV2')->firstOrFail();
            $tv3 = Unit::where('kode', 'TV3')->firstOrFail();
            $paket = PaketHarga::where('nama', 'Paket 3 Jam PS4')->firstOrFail();

            $this->info('2. Mulai sesi PAKET 3 jam di TV2');
            $sesi = $billing->mulai($tv2, $owner, [
                'mode' => Sesi::MODE_PAKET,
                'paket_harga_id' => $paket->id,
                'pelanggan_nama' => 'Tamu Simulasi',
            ]);
            $trx = Transaksi::findOrFail($sesi->transaksi_id);
            $this->line("   Transaksi: {$trx->nomor} | Total: {$rp($trx->total)}");

            $this->info('3. Tambah waktu 30 menit');
            $billing->tambahWaktu($sesi, $owner, 30);
            $this->line('   Total: '.$rp($trx->fresh()->total));

            $this->info('4. Pause lalu resume');
            $billing->pause($sesi, $owner, 'Simulasi');
            $billing->resume($sesi, $owner);

            $this->info('5. Pindah unit TV2 -> TV3');
            $billing->pindahUnit($sesi, $tv3, $owner, 'Stik rusak (simulasi)');
            $this->line('   Unit sekarang: '.Unit::findOrFail($sesi->fresh()->unit_id)->nama);

            $this->info('6. Selesai');
            $billing->selesai($sesi, $owner);
            $trx = $trx->fresh();
            $this->line("   Total tagihan: {$rp($trx->total)} | Status unit TV3: ".Unit::findOrFail($tv3->id)->status);

            $this->info('7. Bayar tunai, uang diterima Rp 50.000');
            $trx = $billing->bayar($trx, $owner, [
                ['metode' => 'tunai', 'jumlah' => $trx->total, 'diterima' => 50000],
            ]);
            $this->line("   Status: {$trx->status} | Kembalian: {$rp($trx->kembalian)} | Status unit TV3: ".Unit::findOrFail($tv3->id)->status);

            $this->info('8. Mulai OPEN BILLING di TV2, lalu batalkan');
            $open = $billing->mulai($tv2, $owner, ['mode' => Sesi::MODE_OPEN, 'pelanggan_nama' => 'Tamu Open']);
            $trxOpen = Transaksi::findOrFail($open->transaksi_id);
            $billing->batalkan($trxOpen, $owner, 'Salah input unit (simulasi)');
            $this->line("   {$trxOpen->nomor}: ".$trxOpen->fresh()->status.' | Status unit TV2: '.Unit::findOrFail($tv2->id)->status);

            $this->info('9. Coba hapus transaksi (harus ditolak)');
            try {
                $trx->delete();
                $this->error('   GAGAL: transaksi terhapus!');
            } catch (Throwable $e) {
                $this->line('   Ditolak: '.$e->getMessage());
            }

            $this->info('10. Tutup shift');
            $seharusnya = $kas->saldo($shift);
            $tutup = $shifts->tutup($shift, $owner, $seharusnya);
            $this->line("   Kas seharusnya: {$rp($tutup->kas_seharusnya)} | Fisik: {$rp($tutup->kas_fisik)} | Selisih: {$rp($tutup->selisih)}");

            if ($this->option('simpan')) {
                DB::commit();
                $this->info('Selesai. Data simulasi DISIMPAN.');
            } else {
                DB::rollBack();
                $this->info('Selesai. Data simulasi di-rollback (database tetap bersih).');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Gagal: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());

            return self::FAILURE;
        }
    }
}
