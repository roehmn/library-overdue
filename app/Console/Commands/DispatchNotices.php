<?php

namespace App\Console\Commands;

use App\Jobs\SendNoticeJob;
use App\Models\Notice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchNotices extends Command
{
    protected $signature = 'notices:dispatch';

    protected $description = 'Put all pending notices on the queue for sending';

    public function handle(): int
    {
        $ids = Notice::where('status', Notice::STATUS_PENDING)->orderBy('id')->pluck('id');

        // If a notice is queued twice, the job's claim step makes the second run a harmless no-op.
        foreach ($ids as $id) {
            SendNoticeJob::dispatch($id);
        }

        Log::info('notice_dispatch.completed', ['dispatched' => $ids->count()]);
        $this->info("Dispatched {$ids->count()} notice(s) to the queue.");

        return self::SUCCESS;
    }
}
