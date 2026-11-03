<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\Loan;
use App\Models\Notice;
use App\Models\Renewal;
use App\Support\ApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Librarian and supervisor view of loans.
 */
class LoanController extends Controller
{
    private const STATUSES = [
        Loan::STATUS_ACTIVE,
        Loan::STATUS_OVERDUE,
        Loan::STATUS_DISPUTED,
        Loan::STATUS_RETURNED,
        Loan::STATUS_CLOSED,
    ];

    public function index(Request $request): JsonResponse
    {
        // Validate query parameters too. Anything from the client is untrusted input.
        $data = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = Loan::query()
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate(15, ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'data' => collect($page->items())->map(fn (Loan $l) => ApiPresenter::loan($l))->values(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        // findOrFail answers 404 NOT_FOUND through the error handler when there is no such loan.
        $loan = Loan::with(['notices', 'renewals', 'disputes'])->findOrFail($id);

        $noticeIds = $loan->notices->pluck('id');

        // The loan's own history plus the history of its notices, oldest first.
        $trail = AuditLog::query()
            ->where(function ($q) use ($loan) {
                $q->where('entity_type', 'loan')->where('entity_id', $loan->id);
            })
            ->orWhere(function ($q) use ($noticeIds) {
                $q->where('entity_type', 'notice')->whereIn('entity_id', $noticeIds);
            })
            ->orderBy('id')
            ->limit(200)
            ->get();

        return response()->json(ApiPresenter::loan($loan) + [
            'notices' => $loan->notices->map(fn (Notice $n) => ApiPresenter::notice($n, true))->values(),
            'renewals' => $loan->renewals->map(fn (Renewal $r) => ApiPresenter::renewal($r))->values(),
            'disputes' => $loan->disputes->map(fn (Dispute $d) => ApiPresenter::dispute($d))->values(),
            'audit_trail' => $trail->map(fn (AuditLog $a) => ApiPresenter::auditEntry($a))->values(),
        ]);
    }
}
