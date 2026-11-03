<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BorrowerController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\NoticeController;
use App\Http\Controllers\Api\SummaryController;
use App\Http\Middleware\EnsureRole;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (contract: docs/openapi.yaml). Laravel adds the /api prefix.
|--------------------------------------------------------------------------
| Two layers protect every route below the login:
|   1. auth:sanctum  - you must hold a valid token (otherwise 401)
|   2. EnsureRole    - your role must be allowed (otherwise 403)
*/

Route::prefix('v1')->group(function () {

    // Public: the only endpoint that works without a token.
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {

        // Any logged-in user.
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Borrowers only: their own records.
        Route::middleware(EnsureRole::class.':borrower')->group(function () {
            Route::get('/me/loans', [BorrowerController::class, 'loans']);
            Route::get('/me/notices', [BorrowerController::class, 'notices']);
        });

        // Librarians and supervisors: review loans and the notice queue.
        Route::middleware(EnsureRole::class.':librarian,supervisor')->group(function () {
            Route::get('/loans', [LoanController::class, 'index']);
            Route::get('/loans/{id}', [LoanController::class, 'show'])->whereNumber('id');
            Route::get('/notices', [NoticeController::class, 'index']);
        });

        // Supervisors only: the daily exception summary.
        Route::get('/summary', [SummaryController::class, 'show'])
            ->middleware(EnsureRole::class.':supervisor');
    });
});