<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error bisnis billing. Pesannya aman ditampilkan ke operator (SweetAlert).
 */
class BillingException extends RuntimeException {}
