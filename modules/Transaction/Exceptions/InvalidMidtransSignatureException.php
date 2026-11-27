<?php

declare(strict_types=1);

namespace Modules\Transaction\Exceptions;

use DomainException;

/**
 * Thrown when an incoming Midtrans webhook fails SHA-512 signature verification.
 */
class InvalidMidtransSignatureException extends DomainException {}
