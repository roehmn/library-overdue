<?php

namespace App\Services;

use App\Exceptions\StateConflictException;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\Loan;
use App\Models\Notice;
use App\Models\Renewal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The business rules for changing a loan's state: return, renewal, dispute, manual resolution.
 *
 * Every method runs in one database transaction and locks the loan row first, so two people
 * acting on the same loan at once cannot both succeed (the second gets 409 STATE_CONFLICT).
 * The loan is always locked BEFORE its renewals, disputes or notices, which avoids deadlocks.
 * Every state change writes an audit_logs row naming the person who did it.
 */
class LoanWorkflow
{
    public const RENEWAL_DAYS = 14;

    public const MAX_RENEWALS = 2;

    private const OPEN_LOAN = [Loan::STATUS_ACTIVE, Loan::STATUS_OVERDUE, Loan::STATUS_DISPUTED];

    private const ESCALATIONS = [
        Notice::TYPE_ESCALATION_LIBRARIAN_7D,
        Notice::TYPE_ESCALATION_SUPERVISOR_14D,
    ];

    /**
     * Librarian or supervisor records that the item is back.
     * Cancels notices that have not been sent yet. The case closes now, or as soon as an open dispute is decided.
     */
    public function recordReturn(int $loanId, User $actor): Loan
    {
        return DB::transaction(function () use ($loanId, $actor) {
            $loan = Loan::whereKey($loanId)->lockForUpdate()->firstOrFail();

            if (! in_array($loan->status, self::OPEN_LOAN, true)) {
                throw new StateConflictException('This loan has already been returned or closed.');
            }

            $from = $loan->status;

            // A renewal request that is still waiting no longer makes sense once the item is back.
            $moot = Renewal::where('loan_id', $loan->id)->where('status', Renewal::STATUS_PENDING)->get();
            foreach ($moot as $renewal) {
                $renewal->update([
                    'status' => Renewal::STATUS_REJECTED,
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                ]);
                AuditLog::record($actor->id, 'renewal.rejected', 'renewal', $renewal->id, Renewal::STATUS_PENDING, Renewal::STATUS_REJECTED, [
                    'reason' => 'loan_returned',
                ]);
            }

            $this->cancelNotices($loan, $actor, null, 'loan_returned');

            $hasOpenDispute = Dispute::where('loan_id', $loan->id)->where('status', Dispute::STATUS_OPEN)->exists();
            $to = $hasOpenDispute ? Loan::STATUS_RETURNED : Loan::STATUS_CLOSED;

            $loan->update(['returned_at' => today(), 'status' => $to]);

            AuditLog::record($actor->id, 'loan.returned', 'loan', $loan->id, $from, $to, [
                'returned_at' => today()->toDateString(),
                'waiting_for_dispute' => $hasOpenDispute,
            ]);
            Log::info('loan.returned', ['loan_id' => $loan->id, 'actor_id' => $actor->id, 'status' => $to]);

            return $loan;
        });
    }

    /** Borrower asks to renew one of their own loans. */
    public function requestRenewal(int $loanId, User $borrower): Renewal
    {
        return DB::transaction(function () use ($loanId, $borrower) {
            $loan = Loan::whereKey($loanId)->lockForUpdate()->firstOrFail();

            $this->assertOwner($loan, $borrower);

            if (! in_array($loan->status, [Loan::STATUS_ACTIVE, Loan::STATUS_OVERDUE], true)) {
                throw new StateConflictException('Only an active or overdue loan can be renewed.');
            }

            if ($loan->renewal_count >= self::MAX_RENEWALS) {
                throw new StateConflictException('The renewal limit for this loan has been reached.');
            }

            if (Renewal::where('loan_id', $loan->id)->where('status', Renewal::STATUS_PENDING)->exists()) {
                throw new StateConflictException('A renewal request for this loan is already waiting for a decision.');
            }

            $renewal = Renewal::create([
                'loan_id' => $loan->id,
                'requested_by' => $borrower->id,
                'status' => Renewal::STATUS_PENDING,
            ]);

            AuditLog::record($borrower->id, 'renewal.requested', 'renewal', $renewal->id, null, Renewal::STATUS_PENDING, [
                'loan_id' => $loan->id,
            ]);
            Log::info('renewal.requested', ['renewal_id' => $renewal->id, 'loan_id' => $loan->id]);

            return $renewal;
        });
    }

    /** Librarian or supervisor approves or rejects a renewal. $decision is "approve" or "reject". */
    public function decideRenewal(int $renewalId, string $decision, User $actor): Renewal
    {
        $loanId = Renewal::findOrFail($renewalId)->loan_id;

        return DB::transaction(function () use ($renewalId, $loanId, $decision, $actor) {
            $loan = Loan::whereKey($loanId)->lockForUpdate()->firstOrFail();
            $renewal = Renewal::whereKey($renewalId)->lockForUpdate()->firstOrFail();

            if ($renewal->status !== Renewal::STATUS_PENDING) {
                throw new StateConflictException('This renewal has already been decided.');
            }

            if ($decision === 'reject') {
                $renewal->update([
                    'status' => Renewal::STATUS_REJECTED,
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                ]);
                AuditLog::record($actor->id, 'renewal.rejected', 'renewal', $renewal->id, Renewal::STATUS_PENDING, Renewal::STATUS_REJECTED, [
                    'loan_id' => $loan->id,
                ]);
                Log::info('renewal.rejected', ['renewal_id' => $renewal->id, 'loan_id' => $loan->id]);

                return $renewal;
            }

            // Approval: the loan must still be renewable.
            if (! in_array($loan->status, [Loan::STATUS_ACTIVE, Loan::STATUS_OVERDUE], true)) {
                throw new StateConflictException('This loan can no longer be renewed.');
            }

            if ($loan->renewal_count >= self::MAX_RENEWALS) {
                throw new StateConflictException('The renewal limit for this loan has been reached.');
            }

            // New due date: 14 days after today, or after the current due date if that is still ahead.
            $base = $loan->due_date->greaterThan(today()) ? $loan->due_date->copy() : today();
            $newDue = $base->addDays(self::RENEWAL_DAYS);
            $oldDue = $loan->due_date->toDateString();
            $from = $loan->status;

            // Notices waiting for the old due date no longer apply. Notices for the new due date
            // are created by the daily review, because the unique key includes the due date.
            $this->cancelNotices($loan, $actor, null, 'loan_renewed');

            $loan->update([
                'due_date' => $newDue,
                'renewal_count' => $loan->renewal_count + 1,
                'status' => Loan::STATUS_ACTIVE,
            ]);

            $renewal->update([
                'status' => Renewal::STATUS_APPROVED,
                'decided_by' => $actor->id,
                'new_due_date' => $newDue,
                'decided_at' => now(),
            ]);

            AuditLog::record($actor->id, 'renewal.approved', 'renewal', $renewal->id, Renewal::STATUS_PENDING, Renewal::STATUS_APPROVED, [
                'loan_id' => $loan->id,
            ]);
            AuditLog::record($actor->id, 'loan.renewed', 'loan', $loan->id, $from, Loan::STATUS_ACTIVE, [
                'old_due_date' => $oldDue,
                'new_due_date' => $newDue->toDateString(),
                'renewal_count' => $loan->renewal_count,
            ]);
            Log::info('renewal.approved', ['renewal_id' => $renewal->id, 'loan_id' => $loan->id]);

            return $renewal;
        });
    }

    /** Borrower disputes the overdue status of one of their own loans. Escalation pauses. */
    public function fileDispute(int $loanId, string $reason, User $borrower): Dispute
    {
        return DB::transaction(function () use ($loanId, $reason, $borrower) {
            $loan = Loan::whereKey($loanId)->lockForUpdate()->firstOrFail();

            $this->assertOwner($loan, $borrower);

            if ($loan->status === Loan::STATUS_DISPUTED) {
                throw new StateConflictException('A dispute is already open for this loan.');
            }

            if ($loan->status !== Loan::STATUS_OVERDUE) {
                throw new StateConflictException('Only an overdue loan can be disputed.');
            }

            $dispute = Dispute::create([
                'loan_id' => $loan->id,
                'filed_by' => $borrower->id,
                'reason' => trim($reason),
                'status' => Dispute::STATUS_OPEN,
            ]);

            // Escalation notices that have not gone out yet are cancelled while the dispute is open.
            $this->cancelNotices($loan, $borrower, self::ESCALATIONS, 'dispute_filed');

            $loan->update(['status' => Loan::STATUS_DISPUTED]);

            AuditLog::record($borrower->id, 'dispute.filed', 'dispute', $dispute->id, null, Dispute::STATUS_OPEN, [
                'loan_id' => $loan->id,
            ]);
            AuditLog::record($borrower->id, 'loan.disputed', 'loan', $loan->id, Loan::STATUS_OVERDUE, Loan::STATUS_DISPUTED, [
                'dispute_id' => $dispute->id,
            ]);
            Log::info('dispute.filed', ['dispute_id' => $dispute->id, 'loan_id' => $loan->id]);

            return $dispute;
        });
    }

    /**
     * Librarian or supervisor decides a dispute. $decision is "upheld" or "rejected".
     *  - upheld:   the library accepts the borrower's claim. The case closes and no more notices go out.
     *  - rejected: the dispute is dismissed. A returned loan closes; otherwise the loan goes back to
     *              active or overdue and the normal reminders and escalations continue.
     */
    public function decideDispute(int $disputeId, string $decision, string $note, User $actor): Dispute
    {
        $loanId = Dispute::findOrFail($disputeId)->loan_id;

        return DB::transaction(function () use ($disputeId, $loanId, $decision, $note, $actor) {
            $loan = Loan::whereKey($loanId)->lockForUpdate()->firstOrFail();
            $dispute = Dispute::whereKey($disputeId)->lockForUpdate()->firstOrFail();

            if ($dispute->status !== Dispute::STATUS_OPEN) {
                throw new StateConflictException('This dispute has already been decided.');
            }

            $upheld = $decision === 'upheld';

            $dispute->update([
                'status' => $upheld ? Dispute::STATUS_UPHELD : Dispute::STATUS_REJECTED,
                'decided_by' => $actor->id,
                'decision_note' => trim($note),
                'decided_at' => now(),
            ]);

            AuditLog::record($actor->id, 'dispute.decided', 'dispute', $dispute->id, Dispute::STATUS_OPEN, $dispute->status, [
                'loan_id' => $loan->id,
            ]);

            // Only a loan that is waiting on this dispute changes state.
            if (in_array($loan->status, [Loan::STATUS_DISPUTED, Loan::STATUS_RETURNED], true)) {
                $from = $loan->status;

                if ($upheld) {
                    $this->cancelNotices($loan, $actor, null, 'dispute_upheld');
                    $to = Loan::STATUS_CLOSED;
                } elseif ($loan->returned_at !== null) {
                    $to = Loan::STATUS_CLOSED;
                } else {
                    $to = $loan->due_date->lessThan(today()) ? Loan::STATUS_OVERDUE : Loan::STATUS_ACTIVE;
                }

                $loan->update(['status' => $to]);

                AuditLog::record($actor->id, 'loan.dispute_resolved', 'loan', $loan->id, $from, $to, [
                    'dispute_id' => $dispute->id,
                    'decision' => $decision,
                ]);
            }

            Log::info('dispute.decided', ['dispute_id' => $dispute->id, 'loan_id' => $loan->id, 'decision' => $decision]);

            return $dispute;
        });
    }

    /** Librarian or supervisor records how a notice in manual review was handled. */
    public function resolveNotice(int $noticeId, string $note, User $actor): Notice
    {
        return DB::transaction(function () use ($noticeId, $note, $actor) {
            $notice = Notice::whereKey($noticeId)->lockForUpdate()->firstOrFail();

            if ($notice->status !== Notice::STATUS_MANUAL_REVIEW) {
                throw new StateConflictException('Only a notice in manual review can be resolved.');
            }

            $notice->update([
                'status' => Notice::STATUS_RESOLVED,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
                'resolution_note' => trim($note),
            ]);

            // The note itself stays on the notice row. The audit entry records who and when, not free text.
            AuditLog::record($actor->id, 'notice.resolved', 'notice', $notice->id, Notice::STATUS_MANUAL_REVIEW, Notice::STATUS_RESOLVED, [
                'loan_id' => $notice->loan_id,
            ]);
            Log::info('notice.resolved', ['notice_id' => $notice->id, 'actor_id' => $actor->id]);

            return $notice;
        });
    }

    /** A borrower may only act on their own loans. */
    private function assertOwner(Loan $loan, User $borrower): void
    {
        if ($loan->borrower_id !== $borrower->id) {
            throw new AccessDeniedHttpException('This loan belongs to another borrower.');
        }
    }

    /**
     * Cancel notices of this loan that have not been sent yet (pending or waiting for a retry).
     * A queued retry job for a cancelled notice does nothing when it runs, because SendNoticeJob
     * only sends notices that are pending or retry_scheduled.
     *
     * @param  array<int,string>|null  $types  limit to these notice types, or null for all types
     */
    private function cancelNotices(Loan $loan, User $actor, ?array $types, string $reason): void
    {
        $query = Notice::where('loan_id', $loan->id)
            ->whereIn('status', [Notice::STATUS_PENDING, Notice::STATUS_RETRY_SCHEDULED]);

        if ($types !== null) {
            $query->whereIn('type', $types);
        }

        foreach ($query->lockForUpdate()->get() as $notice) {
            $from = $notice->status;

            $notice->update(['status' => Notice::STATUS_CANCELLED, 'next_retry_at' => null]);

            AuditLog::record($actor->id, 'notice.cancelled', 'notice', $notice->id, $from, Notice::STATUS_CANCELLED, [
                'reason' => $reason,
            ]);
        }
    }
}
