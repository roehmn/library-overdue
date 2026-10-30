<?php

namespace App\Services;

use App\Exceptions\PermanentMailException;
use App\Exceptions\TransientMailException;
use App\Models\Notice;
use Illuminate\Support\Facades\Mail;

/**
 * The simulated email channel.
 * With MAIL_MAILER=log, "sent" emails are written to storage/logs instead of being delivered,
 * so no real address is ever contacted.
 */
class NoticeMailer
{
    /**
     * @throws PermanentMailException when retrying can never help
     * @throws TransientMailException when a retry may succeed
     */
    public function send(Notice $notice): void
    {
        $notice->loadMissing('loan', 'recipient');

        $email = (string) $notice->recipient?->email;

        // Permanent failure: an invalid address will be invalid on every attempt.
        // The message deliberately omits the address (data minimization).
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PermanentMailException('Recipient email address is invalid.');
        }

        $this->simulateTransientFailure($notice);

        Mail::raw($this->body($notice), function ($message) use ($email, $notice) {
            $message->to($email)->subject($this->subject($notice));
        });
    }

    /**
     * Demonstration only: makes the first N attempts for one chosen loan fail temporarily.
     * Switched off unless NOTICE_SIM_TRANSIENT_REF and NOTICE_SIM_TRANSIENT_FAILURES are set.
     */
    private function simulateTransientFailure(Notice $notice): void
    {
        $ref = config('notices.simulate.transient_ref');
        $failures = (int) config('notices.simulate.transient_failures');

        if ($ref && $failures > 0
            && $notice->loan->external_loan_ref === $ref
            && $notice->attempts <= $failures) {
            throw new TransientMailException('Simulated mail service timeout.');
        }
    }

    private function subject(Notice $notice): string
    {
        return match ($notice->type) {
            Notice::TYPE_REMINDER_3D => 'Library reminder: item due in 3 days',
            Notice::TYPE_REMINDER_DUE => 'Library reminder: item due today',
            Notice::TYPE_OVERDUE_1D => 'Library notice: item overdue',
            Notice::TYPE_ESCALATION_LIBRARIAN_7D => 'Escalation: loan 7 days overdue',
            Notice::TYPE_ESCALATION_SUPERVISOR_14D => 'Escalation: loan 14 days overdue',
            default => 'Library notice',
        };
    }

    private function body(Notice $notice): string
    {
        $title = $notice->loan->item_title;
        $ref = $notice->loan->external_loan_ref;
        $due = $notice->loan->due_date->format('F j, Y');

        // Staff escalations carry the loan reference and title only, never borrower details.
        return match ($notice->type) {
            Notice::TYPE_REMINDER_3D => "Reminder: \"{$title}\" is due on {$due}, in 3 days.",
            Notice::TYPE_REMINDER_DUE => "Reminder: \"{$title}\" is due today ({$due}).",
            Notice::TYPE_OVERDUE_1D => "\"{$title}\" was due on {$due} and is now overdue. Please return it or request a renewal.",
            Notice::TYPE_ESCALATION_LIBRARIAN_7D => "Escalation: loan {$ref} (\"{$title}\") is 7 days overdue. Please review.",
            Notice::TYPE_ESCALATION_SUPERVISOR_14D => "Escalation: loan {$ref} (\"{$title}\") is 14 days overdue and needs supervisor attention.",
            default => "Library notice about \"{$title}\".",
        };
    }
}
