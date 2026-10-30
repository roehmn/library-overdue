<?php

/*
|--------------------------------------------------------------------------
| Notice sending policy (Step 3: retry and recovery policy)
|--------------------------------------------------------------------------
| Every number here can be changed from .env without touching code.
*/

return [

    // Total attempts per notice: the first try plus (tries - 1) retries.
    'tries' => (int) env('NOTICE_TRIES', 4),

    // Seconds to wait before retry 1, retry 2, retry 3 (default: 1 min, 5 min, 15 min).
    // Use "5,10,15" in .env for a quick demonstration.
    'backoff' => array_values(array_filter(
        array_map('intval', explode(',', (string) env('NOTICE_BACKOFF', '60,300,900'))),
        fn (int $seconds) => $seconds > 0
    )),

    // A notice stuck in "sending" longer than this is treated as a crashed run and recovered.
    'stuck_after_minutes' => (int) env('NOTICE_STUCK_MINUTES', 10),

    // Failure simulation for demonstrations. Leave empty or 0 to switch it off.
    'simulate' => [
        // Loan reference whose notice fails temporarily (for example L-1002).
        'transient_ref' => env('NOTICE_SIM_TRANSIENT_REF'),
        // How many attempts fail before the send succeeds.
        'transient_failures' => (int) env('NOTICE_SIM_TRANSIENT_FAILURES', 0),
    ],

];
