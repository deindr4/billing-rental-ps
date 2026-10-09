<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Error bisnis billing. Pesannya aman ditampilkan ke operator (SweetAlert).
 *
 * Pesan tetap diterjemahkan otomatis ke bahasa aktif (kunci = teks Indonesia). Pesan berisi variabel:
 *   throw new BillingException(__('Unit :unit sedang dipakai.', ['unit' => $u->nama]));
 */
class BillingException extends RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message === '' ? '' : __($message), $code, $previous);
    }
}
