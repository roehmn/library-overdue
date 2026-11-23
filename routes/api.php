<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BorrowerController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\NoticeController;
use App\Http\Controllers\Api\SummaryController;
use App\Http\Controllers\Api\WorkflowController;
use App\Http\Middleware\EnsureRole;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (contract: docs/openapi.yaml). Laravel adds the /api prefix.
|--------------------------------------------------------------------------
| Layers that protect every route below the login:
|   1. throttle:api  - request limit per user (otherwise 429)
|   2. auth:sanctum  - you must hold a valid token (otherwise 401)
|   3. EnsureRole    - your role must be allowed (otherwise 403)
| Borrower actions add a further check inside LoanWorkflow: the loan must be the borrower's own.
| The login itself is limited by throttle:login (5 attempts per minute per email and address).
*/

Route::prefix('v1')->group(function () {

    // Public: the only endpoint that works without a token.
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

        // Any logged-in user.
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Borrowers only: their own records and requests.
        Route::middleware(EnsureRole::class.':borrower')->group(function () {
            Route::get('/me/loans', [BorrowerController::class, 'loans']);
            Route::get('/me/notices', [BorrowerController::class, 'notices']);
            Route::post('/loans/{id}/renewals', [WorkflowController::class, 'requestRenewal'])->whereNumber('id');
            Route::post('/loans/{id}/disputes', [WorkflowController::class, 'fileDispute'])->whereNumber('id');
        });

        // Librarians and supervisors: review loans, record returns, decide cases, handle failures.
        Route::middleware(EnsureRole::class.':librarian,supervisor')->group(function () {
            Route::get('/loans', [LoanController::class, 'index']);
            Route::get('/loans/{id}', [LoanController::class, 'show'])->whereNumber('id');
            Route::post('/loans/{id}/return', [WorkflowController::class, 'returnLoan'])->whereNumber('id');
            Route::patch('/renewals/{id}', [WorkflowController::class, 'decideRenewal'])->whereNumber('id');
            Route::patch('/disputes/{id}', [WorkflowController::class, 'decideDispute'])->whereNumber('id');
            Route::get('/notices', [NoticeController::class, 'index']);
            Route::post('/notices/{id}/resolve', [WorkflowController::class, 'resolveNotice'])->whereNumber('id');
        });

        // Supervisors only: the daily exception summary.
        Route::get('/summary', [SummaryController::class, 'show'])
            ->middleware(EnsureRole::class.':supervisor');
    });
});