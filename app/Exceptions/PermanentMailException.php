<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A sending failure that retrying can never fix (for example an invalid address).
 * The notice goes straight to manual review.
 */
class PermanentMailException extends RuntimeException
{
}
