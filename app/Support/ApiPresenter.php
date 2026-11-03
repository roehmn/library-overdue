<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\Loan;
use App\Models\Notice;
use App\Models\Renewal;
use DateTimeImmutable;

/**
 * Turns database records into the exact JSON shapes promised in docs/openapi.yaml.
 * One place for every response shape, so the API and the contract cannot drift apart,
 * and so a database column is never exposed by accident.
 */
class ApiPresenter
{
    public static function loan(Loan $loan): array
    {
        return [
            'id' => $loan->id,
            'external_loan_ref' => $loan->external_loan_ref,
            'item_title' => $loan->item_title,
            'due_date' => $loan->due_date->toDateString(),
            'returned_at' => $loan->returned_at?->toDateString(),
            'renewal_count' => $loan->renewal_count,
            'status' => $loan->status,
            'days_overdue' => self::daysOverdue($loan),
        ];
    }

    /**
     * Borrowers see their notices without internal error text.
     * Staff ($forStaff = true) also see last_error, which they need to handle failures.
     */
    public static function notice(Notice $notice, bool $forStaff = false): array
    {
        $data = [
            'id' => $notice->id,
            'loan_id' => $notice->loan_id,
            'type' => $notice->type,
            'status' => $notice->status,
            'attempts' => $notice->attempts,
            'sent_at' => $notice->sent_at?->toIso8601String(),
        ];

        if ($forStaff) {
            $data['last_error'] = $notice->last_error;
        }

        return $data;
    }

    public static function renewal(Renewal $renewal): array
    {
        return [
            'id' => $renewal->id,
            'loan_id' => $renewal->loan_id,
            'status' => $renewal->status,
            'new_due_date' => $renewal->new_due_date?->toDateString(),
        ];
    }

    public static function dispute(Dispute $dispute): array
    {
        return [
            'id' => $dispute->id,
            'loan_id' => $dispute->loan_id,
            'reason' => $dispute->reason,
            'status' => $dispute->status,
            'decision_note' => $dispute->decision_note,
        ];
    }

    public static function auditEntry(AuditLog $entry): array
    {
        return [
            'action' => $entry->action,
            'from_state' => $entry->from_state,
            'to_state' => $entry->to_state,
            'created_at' => $entry->created_at->toIso8601String(),
        ];
    }

    /** Whole days past the due date. 0 when not overdue, returned, or closed. */
    private static function daysOverdue(Loan $loan): int
    {
        if (in_array($loan->status, [Loan::STATUS_RETURNED, Loan::STATUS_CLOSED], true)) {
            return 0;
        }

        $due = new DateTimeImmutable($loan->due_date->toDateString());
        $today = new DateTimeImmutable(today()->toDateString());

        return $today > $due ? (int) $due->diff($today)->days : 0;
    }
}
