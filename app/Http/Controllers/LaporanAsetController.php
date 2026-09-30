<?php

namespace App\Http\Controllers;

use App\Models\Aset;
use App\Models\Cabang;
use App\Services\Aset\AsetService;
use App\Support\Tenancy;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unduh laporan penyusutan aset cabang aktif: PDF atau CSV (dibuka di Excel).
 */
class LaporanAsetController extends Controller
{
    public function __invoke(string $format, AsetService $service): Response
    {
        $cabang = Cabang::with('tenant')->find(app(Tenancy::class)->cabangId());
        $baris = $service->laporanPenyusutan();
        $nama = 'Penyusutan-Aset-'.($cabang?->kode ?? 'CBG').'-'.now()->format('Ymd');

        if ($format === 'pdf') {
            return Pdf::loadView('pdf.laporan-aset', [
                'cabang' => $cabang,
                'baris' => $baris,
                'r' => $service->ringkasan(),
                'lihatLaba' => (bool) auth()->user()->can('laporan.laba'),
            ])->setPaper('a4', 'landscape')->stream($nama.'.pdf');
        }

        return response()->streamDownload(function () use ($baris) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM supaya Excel membaca UTF-8
            fputcsv($out, ['Aset', 'Kategori', 'Unit', 'No seri', 'Tanggal beli', 'Harga perolehan', 'Nilai sisa', 'Umur (bulan)',
                'Penyusutan per bulan', 'Akumulasi penyusutan', 'Nilai buku', 'Status', 'Tanggal lepas', 'Nilai lepas', 'Garansi sampai'], ';');

            foreach ($baris as $b) {
                /** @var Aset $a */
                $a = $b['aset'];
                fputcsv($out, [
                    $a->nama, Aset::KATEGORI[$a->kategori] ?? $a->kategori, $a->unit?->nama, $a->serial,
                    $a->tanggal_beli->format('Y-m-d'), $a->harga_perolehan, $a->nilai_sisa, $a->umur_bulan,
                    $b['per_bulan'], $b['akumulasi'], $b['nilai_buku'], Aset::STATUS[$a->status],
                    $a->dilepas_pada?->format('Y-m-d'), $a->nilai_lepas, $a->garansi_sampai?->format('Y-m-d'),
                ], ';');
            }

            fclose($out);
        }, $nama.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
