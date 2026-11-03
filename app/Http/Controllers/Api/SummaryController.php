<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExceptionSummary;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Supervisor view of the daily exception summary.
 * Uses the same service as the 17:00 email, so the screen and the email can never disagree.
 */
class SummaryController extends Controller
{
    public function show(Request $request, ExceptionSummary $summary): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = isset($data['date']) ? Carbon::createFromFormat('Y-m-d', $data['date'])->startOfDay() : today();

        return response()->json($summary->build($date));
    }
}
