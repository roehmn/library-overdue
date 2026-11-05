<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The request is well-formed but conflicts with the current state of the record
 * (for example, returning a loan that is already returned). Answers 409 STATE_CONFLICT.
 *
 * It is not a bug, so it is logged at info level instead of being reported as an error.
 * Messages are fixed sentences and never contain user data.
 */
class StateConflictException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'STATE_CONFLICT',
                'message' => $this->getMessage(),
            ],
        ], 409);
    }

    public function report(): void
    {
        Log::info('api.state_conflict', ['reason' => $this->getMessage()]);
    }
}
