<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A temporary sending failure (timeout, service busy). Retrying may succeed.
 */
class TransientMailException extends RuntimeException
{
}
