<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\User;
use App\Services\SimulatedLoanSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SyncLoans extends Command
{
    protected $signature = 'loans:sync';

    protected $description = 'Synchronize loans from the simulated library loan source';

    public function handle(SimulatedLoanSource $source): int
    {
        // 1. Fetch. If the source is down, report it and stop cleanly (the "unavailable service" path).
        try {
            $records = $source->fetch();
        } catch (Throwable $e) {
            Log::error('loan_sync.source_unavailable', ['error' => $e->getMessage()]);
            $this->error('Loan source unavailable: '.$e->getMessage());

            return self::FAILURE;
        }

        $created = 0;
        $unchanged = 0;
        $rejected = 0;

        foreach ($records as $record) {
            $ref = is_array($record) ? (string) ($record['external_loan_ref'] ?? 'unknown') : 'unknown';

            // 2. Validate. Never trust incoming data, even from "our own" source.
            $validator = Validator::make(is_array($record) ? $record : [], [
                'external_loan_ref' => ['required', 'string', 'max:50'],
                'borrower_email' => ['required', 'string', 'max:255'],
                'item_title' => ['required', 'string', 'max:255'],
                'item_barcode' => ['required', 'string', 'max:50'],
                'due_in_days' => ['required', 'integer', 'between:-365,365'],
            ]);

            if ($validator->fails()) {
                $rejected++;
                // Data minimization: log the loan reference and the failing fields, never personal details.
                Log::warning('loan_sync.record_rejected', [
                    'external_loan_ref' => $ref,
                    'reason' => 'validation_failed',
                    'fields' => array_keys($validator->errors()->toArray()),
                ]);

                continue;
            }

            // 3. The borrower must exist and have the borrower role.
            $borrower = User::where('email', $record['borrower_email'])->where('role', 'borrower')->first();

            if (! $borrower) {
                $rejected++;
                Log::warning('loan_sync.record_rejected', [
                    'external_loan_ref' => $ref,
                    'reason' => 'unknown_borrower',
                ]);

                continue;
            }

            // 4. Idempotency: a loan we already have is left alone, so running the sync twice is safe.
            if (Loan::where('external_loan_ref', $record['external_loan_ref'])->exists()) {
                $unchanged++;

                continue;
            }

            // 5. Create the loan.
            $dueDate = today()->addDays((int) $record['due_in_days']);

            $loan = Loan::create([
                'borrower_id' => $borrower->id,
                'external_loan_ref' => $record['external_loan_ref'],
                'item_title' => $record['item_title'],
                'item_barcode' => $record['item_barcode'],
                'borrowed_at' => $dueDate->copy()->subDays(14),
                'due_date' => $dueDate,
                'status' => Loan::STATUS_ACTIVE,
            ]);

            AuditLog::record(null, 'loan.synced', 'loan', $loan->id, null, Loan::STATUS_ACTIVE, [
                'external_loan_ref' => $loan->external_loan_ref,
                'due_date' => $dueDate->toDateString(),
            ]);

            $created++;
        }

        Log::info('loan_sync.completed', compact('created', 'unchanged', 'rejected'));
        $this->info("Loan sync finished. Created: {$created}, unchanged: {$unchanged}, rejected: {$rejected}.");

        return self::SUCCESS;
    }
}
