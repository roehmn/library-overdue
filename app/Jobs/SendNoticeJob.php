<?php

namespace App\Jobs;

use App\Exceptions\PermanentMailException;
use App\Models\AuditLog;
use App\Models\Notice;
use App\Services\NoticeMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one notice and moves it through its life cycle:
 *
 *   pending -> sending -> sent
 *                 |
 *                 +--> retry_scheduled -> sending (again, up to the limit)
 *                 |
 *                 +--> manual_review   (permanent failure, or retries exhausted)
 */
class SendNoticeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Maximum attempts (set from config in the constructor). */
    public int $tries = 4;

    public function __construct(public int $noticeId)
    {
        $this->tries = (int) config('notices.tries', 4);
    }

    /** Seconds to wait before each retry. Laravel reads this when the job is queued. */
    public function backoff(): array
    {
        return config('notices.backoff') ?: [60, 300, 900];
    }

    public function handle(NoticeMailer $mailer): void
    {
        $notice = $this->claim();

        // Already sent, cancelled, or being handled by someone else: do nothing (safe to run twice).
        if ($notice === null) {
            Log::info('notice.send_skipped', ['notice_id' => $this->noticeId]);

            return;
        }

        try {
            $mailer->send($notice);
        } catch (PermanentMailException $e) {
            // Retrying can never help. fail() stops retries and runs failed() below.
            Log::error('notice.send_permanent_failure', [
                'notice_id' => $notice->id,
                'attempt' => $notice->attempts,
                'error' => $e->getMessage(),
            ]);
            $this->fail($e);

            return;
        } catch (Throwable $e) {
            // Anything else is treated as temporary, but the number of retries is still bounded.
            if ($notice->attempts < $this->tries) {
                $this->scheduleRetry($notice, $e);
            }

            throw $e; // Laravel releases the job and waits for the backoff time.
        }

        $this->markSent($notice);
    }

    /**
     * Runs when the job will not be tried again: permanent failure or retries exhausted.
     * This is the hand-off to a human.
     */
    public function failed(?Throwable $exception): void
    {
        $notice = Notice::find($this->noticeId);

        if (! $notice || in_array($notice->status, [Notice::STATUS_SENT, Notice::STATUS_RESOLVED, Notice::STATUS_CANCELLED], true)) {
            return;
        }

        $from = $notice->status;
        $permanent = $exception instanceof PermanentMailException;

        $notice->update([
            'status' => Notice::STATUS_MANUAL_REVIEW,
            'last_error' => $this->describe($exception),
            'next_retry_at' => null,
        ]);

        AuditLog::record(null, 'notice.manual_review', 'notice', $notice->id, $from, Notice::STATUS_MANUAL_REVIEW, [
            'reason' => $permanent ? 'permanent_failure' : 'retries_exhausted',
            'attempts' => $notice->attempts,
        ]);

        Log::error('notice.manual_review', [
            'notice_id' => $notice->id,
            'loan_id' => $notice->loan_id,
            'reason' => $permanent ? 'permanent_failure' : 'retries_exhausted',
            'attempts' => $notice->attempts,
        ]);
    }

    /**
     * Take the notice for sending. The row is locked so two workers can never send the same notice.
     * Returns null when the notice is not in a sendable state.
     */
    private function claim(): ?Notice
    {
        return DB::transaction(function () {
            $notice = Notice::whereKey($this->noticeId)->lockForUpdate()->first();

            if (! $notice || ! in_array($notice->status, [Notice::STATUS_PENDING, Notice::STATUS_RETRY_SCHEDULED], true)) {
                return null;
            }

            $from = $notice->status;

            $notice->update([
                'status' => Notice::STATUS_SENDING,
                'attempts' => $notice->attempts + 1,
                'next_retry_at' => null,
            ]);

            AuditLog::record(null, 'notice.sending', 'notice', $notice->id, $from, Notice::STATUS_SENDING, [
                'attempt' => $notice->attempts,
            ]);

            return $notice;
        });
    }

    private function markSent(Notice $notice): void
    {
        $notice->update([
            'status' => Notice::STATUS_SENT,
            'sent_at' => now(),
            'last_error' => null,
            'next_retry_at' => null,
        ]);

        AuditLog::record(null, 'notice.sent', 'notice', $notice->id, Notice::STATUS_SENDING, Notice::STATUS_SENT, [
            'attempt' => $notice->attempts,
        ]);

        Log::info('notice.sent', [
            'notice_id' => $notice->id,
            'loan_id' => $notice->loan_id,
            'type' => $notice->type,
            'attempt' => $notice->attempts,
        ]);
    }

    private function scheduleRetry(Notice $notice, Throwable $e): void
    {
        $waits = $this->backoff();
        $delay = (int) $waits[min($notice->attempts - 1, count($waits) - 1)];

        $notice->update([
            'status' => Notice::STATUS_RETRY_SCHEDULED,
            'last_error' => $this->describe($e),
            'next_retry_at' => now()->addSeconds($delay),
        ]);

        AuditLog::record(null, 'notice.retry_scheduled', 'notice', $notice->id, Notice::STATUS_SENDING, Notice::STATUS_RETRY_SCHEDULED, [
            'attempt' => $notice->attempts,
            'retry_in_seconds' => $delay,
            'error' => $this->describe($e),
        ]);

        Log::warning('notice.retry_scheduled', [
            'notice_id' => $notice->id,
            'attempt' => $notice->attempts,
            'max_attempts' => $this->tries,
            'retry_in_seconds' => $delay,
            'error' => $this->describe($e),
        ]);
    }

    private function describe(?Throwable $e): string
    {
        return $e === null ? 'Unknown error' : Str::limit(class_basename($e).': '.$e->getMessage(), 250);
    }
}
