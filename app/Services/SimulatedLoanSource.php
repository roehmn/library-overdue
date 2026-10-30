<?php

namespace App\Services;

use RuntimeException;

/**
 * Stands in for the real library loan system.
 * Reads a local JSON file so the whole project runs on fictional, controlled data.
 *
 * To demonstrate the "unavailable service" path, temporarily rename database/simulated/loans.json
 * and run the sync: fetch() throws, and the command reports the failure instead of crashing.
 */
class SimulatedLoanSource
{
    public function fetch(): array
    {
        $path = base_path('database/simulated/loans.json');

        if (! is_file($path)) {
            throw new RuntimeException('Loan source is unavailable.');
        }

        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($data['loans'] ?? null) ? $data['loans'] : [];
    }
}
