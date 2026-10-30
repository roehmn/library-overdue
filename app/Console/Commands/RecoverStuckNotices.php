<?php

namespace App\Console\Commands;

use App\Jobs\SendNoticeJob;
use App\Models\AuditLog;
use App\Models\Notice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Crash recovery. If a worker dies mid-send, its notice stays in "sending" forever.
 * This command finds those notices and either re-queues them or, if attempts are used up,
 * hands them to a human.
 */
class RecoverStuckNotices extends Command
{
    protected $signature = 'notices:recover';

    protected $description = 'Recover notices stuck in the sending state';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) config('notices.stuck_after_minutes'));
        $maxAttempts = (int) config('notices.tries');

        $stuck = Notice::where('status', Notice::STATUS_SENDING)
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->get();

        $requeued = 0;
        $escalated = 0;

        foreach ($stuck as $notice) {
            if ($notice->attempts >= $maxAttempts) {
                $notice->update([
                    'status' => Notice::STATUS_MANUAL_REVIEW,
                    'last_error' => 'Stuck in sending with no attempts left.',
                ]);
                AuditLog::record(null, 'notice.manual_review', 'notice', $notice->id, Notice::STATUS_SENDING, Notice::STATUS_MANUAL_REVIEW, [
                    'reason' => 'stuck_no_attempts_left',
                    'attempts' => $notice->attempts,
                ]);
                Log::error('notice.manual_review', ['notice_id' => $notice->id, 'reason' => 'stuck_no_attempts_left']);
                $escalated++;

                continue;
            }

            $notice->update(['status' => Notice::STATUS_PENDING]);
            AuditLog::record(null, 'notice.recovered', 'notice', $notice->id, Notice::STATUS_SENDING, Notice::STATUS_PENDING, [
                'reason' => 'stuck_in_sending',
                'attempts' => $notice->attempts,
            ]);
            Log::warning('notice.recovered', ['notice_id' => $notice->id, 'attempts' => $notice->attempts]);
            SendNoticeJob::dispatch($notice->id);
            $requeued++;
        }

        $this->info("Recovery finished. Re-queued: {$requeued}, sent to manual review: {$escalated}.");

        return self::SUCCESS;
    }
}
