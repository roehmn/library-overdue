<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\Notice;
use App\Support\ApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Borrower-only endpoints. Every query is filtered by the logged-in user's id,
 * so a borrower can never see another borrower's records.
 */
class BorrowerController extends Controller
{
    public function loans(Request $request): JsonResponse
    {
        $loans = Loan::where('borrower_id', $request->user()->id)
            ->orderBy('due_date')
            ->get();

        return response()->json(['data' => $loans->map(fn (Loan $l) => ApiPresenter::loan($l))->values()]);
    }

    public function notices(Request $request): JsonResponse
    {
        $notices = Notice::where('recipient_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        // Borrowers get the notice without internal error details.
        return response()->json(['data' => $notices->map(fn (Notice $n) => ApiPresenter::notice($n))->values()]);
    }
}
