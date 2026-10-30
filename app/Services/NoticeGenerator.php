<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\Notice;
use App\Models\User;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Due-date review: decides which notices are due on a given day and creates them as "pending".
 *
 * Notice timeline (ADR-002), keyed by "days until due" (negative = overdue):
 *    3 -> reminder_3d              (borrower)
 *    0 -> reminder_due             (borrower)
 *   -1 -> overdue_1d               (borrower)
 *   -7 -> escalation_librarian_7d  (librarian)
 *  -14 -> escalation_supervisor_14d (supervisor)
 */
class NoticeGenerator
{
    private const TIMELINE = [
        3 => [Notice::TYPE_REMINDER_3D, 'borrower'],
        0 => [Notice::TYPE_REMINDER_DUE, 'borrower'],
        -1 => [Notice::TYPE_OVERDUE_1D, 'borrower'],
        -7 => [Notice::TYPE_ESCALATION_LIBRARIAN_7D, 'librarian'],
        -14 => [Notice::TYPE_ESCALATION_SUPERVISOR_14D, 'supervisor'],
    ];

    private const ESCALATION_TYPES = [
        Notice::TYPE_ESCALATION_LIBRARIAN_7D,
        Notice::TYPE_ESCALATION_SUPERVISOR_14D,
    ];

    /**
     * @return array{marked_overdue:int, created:int, existing:int, skipped:int}
     */
    public function generate(CarbonInterface $today): array
    {
        $result = ['marked_overdue' => 0, 'created' => 0, 'existing' => 0, 'skipped' => 0];

        // Looked up once per run. If a role has nobody assigned, escalations to it are skipped and logged.
        $staff = [
            'librarian' => User::where('role', 'librarian')->orderBy('id')->first(),
            'supervisor' => User::where('role', 'supervisor')->orderBy('id')->first(),
        ];

        $loans = Loan::whereIn('status', [Loan::STATUS_ACTIVE, Loan::STATUS_OVERDUE, Loan::STATUS_DISPUTED])
            ->orderBy('id')
            ->get();

        foreach ($loans as $loan) {
            $daysUntilDue = $this->daysUntilDue($today, $loan);

            // Step 1: status review. An active loan past its due date becomes overdue.
            if ($daysUntilDue < 0 && $loan->status === Loan::STATUS_ACTIVE) {
                $loan->update(['status' => Loan::STATUS_OVERDUE]);
                AuditLog::record(null, 'loan.marked_overdue', 'loan', $loan->id, Loan::STATUS_ACTIVE, Loan::STATUS_OVERDUE, [
                    'due_date' => $loan->due_date->toDateString(),
                ]);
                $result['marked_overdue']++;
            }

            // Step 2: is a notice due today for this loan?
            if (! isset(self::TIMELINE[$daysUntilDue])) {
                continue;
            }

            [$type, $recipientRole] = self::TIMELINE[$daysUntilDue];

            // A dispute pauses escalation (ADR-002) until a librarian decides it.
            if ($loan->status === Loan::STATUS_DISPUTED && in_array($type, self::ESCALATION_TYPES, true)) {
                $result['skipped']++;
                Log::info('notice.skipped', ['loan_id' => $loan->id, 'type' => $type, 'reason' => 'loan_disputed']);

                continue;
            }

            // Step 3: who receives it?
            $recipientId = $recipientRole === 'borrower' ? $loan->borrower_id : $staff[$recipientRole]?->id;

            if ($recipientId === null) {
                $result['skipped']++;
                Log::error('notice.skipped', ['loan_id' => $loan->id, 'type' => $type, 'reason' => 'no_recipient_for_role', 'role' => $recipientRole]);

                continue;
            }

            // Step 4: create it. Two layers stop duplicates: firstOrCreate (the normal check)
            // and the database unique key (the safety net if two runs overlap).
            try {
                $notice = Notice::firstOrCreate(
                    ['loan_id' => $loan->id, 'type' => $type, 'due_date' => $loan->due_date->toDateString()],
                    ['recipient_id' => $recipientId, 'status' => Notice::STATUS_PENDING]
                );
            } catch (UniqueConstraintViolationException $e) {
                $result['existing']++;

                continue;
            }

            if (! $notice->wasRecentlyCreated) {
                $result['existing']++;

                continue;
            }

            AuditLog::record(null, 'notice.created', 'notice', $notice->id, null, Notice::STATUS_PENDING, [
                'loan_id' => $loan->id,
                'type' => $type,
            ]);
            Log::info('notice.created', ['notice_id' => $notice->id, 'loan_id' => $loan->id, 'type' => $type]);
            $result['created']++;
        }

        return $result;
    }

    /**
     * Whole days from $today until the loan's due date (negative when overdue).
     * Plain PHP dates are used so the arithmetic is exact and easy to test.
     */
    private function daysUntilDue(CarbonInterface $today, Loan $loan): int
    {
        $from = new DateTimeImmutable($today->toDateString());
        $to = new DateTimeImmutable($loan->due_date->toDateString());
        $diff = $from->diff($to);

        return $diff->invert ? -$diff->days : $diff->days;
    }
}
