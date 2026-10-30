<?php

namespace App\Console\Commands;

use App\Services\NoticeGenerator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateNotices extends Command
{
    protected $signature = 'notices:generate {--date= : Review as of this date (YYYY-MM-DD). Used to recover a missed run.}';

    protected $description = 'Review due dates and create the notices that are due today';

    public function handle(NoticeGenerator $generator): int
    {
        $option = $this->option('date');

        if ($option !== null && ! Carbon::hasFormat($option, 'Y-m-d')) {
            $this->error('Invalid --date. Use the format YYYY-MM-DD.');

            return self::FAILURE;
        }

        $today = $option !== null ? Carbon::createFromFormat('Y-m-d', $option)->startOfDay() : today();

        $result = $generator->generate($today);

        Log::info('notice_generation.completed', ['as_of' => $today->toDateString()] + $result);

        $this->info(sprintf(
            'Notice generation as of %s. Marked overdue: %d, created: %d, existing: %d, skipped: %d.',
            $today->toDateString(),
            $result['marked_overdue'],
            $result['created'],
            $result['existing'],
            $result['skipped']
        ));

        return self::SUCCESS;
    }
}
