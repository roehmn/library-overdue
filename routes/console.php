<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled automation (ADR-002). All times are Asia/Manila.
|--------------------------------------------------------------------------
| withoutOverlapping() stops a second copy from starting while the first is still running.
| A 17:00 exception summary will be added in a later step.
*/

// 01:00 - pull the latest loans from the (simulated) library loan source.
Schedule::command('loans:sync')
    ->dailyAt('01:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

// 08:00 - review due dates and create the notices that are due today.
Schedule::command('notices:generate')
    ->dailyAt('08:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();