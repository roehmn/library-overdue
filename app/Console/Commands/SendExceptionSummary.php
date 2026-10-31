<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ExceptionSummary;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendExceptionSummary extends Command
{
    protected $signature = 'notices:summary {--date= : Summarize this date (YYYY-MM-DD). Defaults to today.}';

    protected $description = 'Build the daily exception summary and email it to the supervisor(s)';

    public function handle(ExceptionSummary $builder): int
    {
        $option = $this->option('date');

        if ($option !== null && ! Carbon::hasFormat($option, 'Y-m-d')) {
            $this->error('Invalid --date. Use the format YYYY-MM-DD.');

            return self::FAILURE;
        }

        $date = $option !== null ? Carbon::createFromFormat('Y-m-d', $option)->startOfDay() : today();
        $summary = $builder->build($date);
        $body = $this->format($summary);

        $counts = [
            'as_of' => $summary['date'],
            'manual_review' => count($summary['manual_review']),
            'recovered' => count($summary['recovered']),
            'escalations' => count($summary['escalations']),
            'open_disputes' => $summary['open_disputes'],
            'pending_renewals' => $summary['pending_renewals'],
        ];

        $this->line($body);

        // Send to every supervisor with a valid address. A failed email must not lose the summary:
        // it is already printed above, logged below, and will be shown in the app.
        $sent = 0;
        foreach (User::where('role', 'supervisor')->get() as $supervisor) {
            if (! filter_var($supervisor->email, FILTER_VALIDATE_EMAIL)) {
                Log::warning('exception_summary.recipient_invalid', ['user_id' => $supervisor->id]);

                continue;
            }

            try {
                Mail::raw($body, fn ($message) => $message
                    ->to($supervisor->email)
                    ->subject('Daily exception summary: '.$summary['date']));
                $sent++;
            } catch (Throwable $e) {
                Log::error('exception_summary.send_failed', ['user_id' => $supervisor->id, 'error' => $e->getMessage()]);
            }
        }

        Log::info('exception_summary.generated', $counts + ['emails_sent' => $sent]);
        $this->info("Summary generated. Emails sent: {$sent}.");

        return self::SUCCESS;
    }

    private function format(array $s): string
    {
        $lines = ["Daily exception summary for {$s['date']}", ''];

        $lines[] = 'Needs human action (manual review): '.count($s['manual_review']);
        foreach ($s['manual_review'] as $n) {
            $lines[] = " - Notice #{$n['notice_id']} | loan {$n['loan_ref']} | {$n['type']} | attempts {$n['attempts']} | {$n['last_error']}";
        }

        $lines[] = '';
        $lines[] = 'Recovered after retries today: '.count($s['recovered']);
        foreach ($s['recovered'] as $n) {
            $lines[] = " - Notice #{$n['notice_id']} | loan {$n['loan_ref']} | {$n['type']} | sent on attempt {$n['attempts']}";
        }

        $lines[] = '';
        $lines[] = 'Escalations created today: '.count($s['escalations']);
        foreach ($s['escalations'] as $n) {
            $lines[] = " - Notice #{$n['notice_id']} | loan {$n['loan_ref']} | {$n['type']} | status {$n['status']}";
        }

        $lines[] = '';
        $lines[] = "Open disputes: {$s['open_disputes']}";
        $lines[] = "Pending renewals: {$s['pending_renewals']}";

        return implode("\n", $lines);
    }
}
