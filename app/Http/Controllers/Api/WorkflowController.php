<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LoanWorkflow;
use App\Support\ApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Endpoints that change state. Each one validates the input, then hands the work to LoanWorkflow,
 * which holds the business rules. The route decides WHICH ROLES may call each endpoint.
 */
class WorkflowController extends Controller
{
    public function returnLoan(Request $request, int $id, LoanWorkflow $workflow): JsonResponse
    {
        $loan = $workflow->recordReturn($id, $request->user());

        return response()->json(ApiPresenter::loan($loan));
    }

    public function requestRenewal(Request $request, int $id, LoanWorkflow $workflow): JsonResponse
    {
        $renewal = $workflow->requestRenewal($id, $request->user());

        return response()->json(ApiPresenter::renewal($renewal), 201);
    }

    public function decideRenewal(Request $request, int $id, LoanWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
        ]);

        $renewal = $workflow->decideRenewal($id, $data['decision'], $request->user());

        return response()->json(ApiPresenter::renewal($renewal));
    }

    public function fileDispute(Request $request, int $id, LoanWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $dispute = $workflow->fileDispute($id, $data['reason'], $request->user());

        return response()->json(ApiPresenter::dispute($dispute), 201);
    }

    public function decideDispute(Request $request, int $id, LoanWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['upheld', 'rejected'])],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $dispute = $workflow->decideDispute($id, $data['decision'], $data['note'], $request->user());

        return response()->json(ApiPresenter::dispute($dispute));
    }

    public function resolveNotice(Request $request, int $id, LoanWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $notice = $workflow->resolveNotice($id, $data['note'], $request->user());

        return response()->json(ApiPresenter::notice($notice, true));
    }
}
