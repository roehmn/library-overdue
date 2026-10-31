<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\Notice;
use App\Models\Renewal;
use Carbon\CarbonInterface;

/**
 * Builds the daily exception summary: what needs a human, what recovered on its own,
 * and what was escalated. Returns plain arrays so the command (email) and, later, the API can share it.
 *
 * Data minimization: loan references and notice details only. No borrower names or email addresses.
 */
class ExceptionSummary
{
    public function build(CarbonInterface $date): array
    {
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        // Needs human action. Not limited to today: an unresolved failure stays on the list until handled.
        $manualReview = Notice::with('loan')
            ->where('status', Notice::STATUS_MANUAL_REVIEW)
            ->orderBy('id')
            ->get()
            ->map(fn (Notice $n) => [
                'notice_id' => $n->id,
                'loan_ref' => $n->loan->external_loan_ref,
                'type' => $n->type,
                'attempts' => $n->attempts,
                'last_error' => $n->last_error,
            ])->all();

        // Transient failures that fixed themselves today (sent, but only after more than one attempt).
        $recovered = Notice::with('loan')
            ->where('status', Notice::STATUS_SENT)
            ->where('attempts', '>', 1)
            ->whereBetween('sent_at', [$start, $end])
            ->orderBy('id')
            ->get()
            ->map(fn (Notice $n) => [
                'notice_id' => $n->id,
                'loan_ref' => $n->loan->external_loan_ref,
                'type' => $n->type,
                'attempts' => $n->attempts,
            ])->all();

        // Escalation notices created today (librarian at 7 days, supervisor at 14 days).
        $escalations = Notice::with('loan')
            ->whereIn('type', [Notice::TYPE_ESCALATION_LIBRARIAN_7D, Notice::TYPE_ESCALATION_SUPERVISOR_14D])
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('id')
            ->get()
            ->map(fn (Notice $n) => [
                'notice_id' => $n->id,
                'loan_ref' => $n->loan->external_loan_ref,
                'type' => $n->type,
                'status' => $n->status,
            ])->all();

        return [
            'date' => $date->toDateString(),
            'manual_review' => $manualReview,
            'recovered' => $recovered,
            'escalations' => $escalations,
            'open_disputes' => Dispute::where('status', Dispute::STATUS_OPEN)->count(),
            'pending_renewals' => Renewal::where('status', Renewal::STATUS_PENDING)->count(),
        ];
    }
}
