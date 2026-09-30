<?php

namespace App\Services\Gateway;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * Kontrak payment gateway untuk QRIS dinamis (nominal ditentukan).
 * Implementasi: SimulasiGateway (uji tanpa akun), Tripay, Midtrans, Duitku, iPaymu, DOKU, Winpay.
 * Gateway yang hanya memberi tautan pembayaran (DOKU Checkout) mengisi qr_string dengan tautan itu
 * dan data['url_bayar'] (HP pelanggan diberi tombol "Bayar sekarang").
 */
interface Gateway
{
    /**
     * Buat tagihan QRIS.
     *
     * @return array{referensi:string, qr_string:string, kedaluwarsa:CarbonInterface, biaya:int, data:array}
     */
    public function buatQris(string $merchantRef, int $nominal, string $keterangan, int $menitBerlaku): array;

    /**
     * Status tagihan di gateway.
     *
     * @return array{status:'menunggu'|'dibayar'|'kedaluwarsa'|'gagal', biaya:?int, dibayar_pada:?CarbonInterface, data:array}
     */
    public function cekStatus(string $referensi, string $merchantRef): array;

    /**
     * Validasi & baca notifikasi (callback) dari gateway. null = tanda tangan tidak valid.
     *
     * @return array{merchant_ref:string, referensi:string, status:string, biaya:?int}|null
     */
    public function bacaCallback(Request $request): ?array;

    /** Balasan HTTP yang diharapkan gateway setelah callback diterima */
    public function balasanCallback(): array;

    /**
     * Tes kredensial ke gateway (tombol "Tes koneksi"). Gagal = BillingException.
     *
     * @return list<string> kanal/metode QRIS yang tersedia
     */
    public function tes(): array;
}
