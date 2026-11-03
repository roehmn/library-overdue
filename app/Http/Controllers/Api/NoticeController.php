<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Support\ApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff view of notices, including the manual-review queue (?status=manual_review).
 */
class NoticeController extends Controller
{
    private const STATUSES = [
        Notice::STATUS_PENDING,
        Notice::STATUS_SENDING,
        Notice::STATUS_RETRY_SCHEDULED,
        Notice::STATUS_SENT,
        Notice::STATUS_MANUAL_REVIEW,
        Notice::STATUS_RESOLVED,
        Notice::STATUS_CANCELLED,
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ]);

        $notices = Notice::query()
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        // Staff also see last_error, because they need it to handle a failed notice.
        return response()->json([
            'data' => $notices->map(fn (Notice $n) => ApiPresenter::notice($n, true))->values(),
        ]);
    }
}
