<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'The submitted data is invalid.',
                    'fields' => $e->errors(),
                ]], 422);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Please log in to continue.',
                ]], 401);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException|AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You are not allowed to perform this action.',
                ]], 403);
            }
        });

        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'The requested resource was not found.',
                ]], 404);
            }
        });

        // Too many requests (rate limit): tell the client when to retry.
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => [
                    'code' => 'TOO_MANY_REQUESTS',
                    'message' => 'Too many attempts. Please wait and try again.',
                ]], 429, $e->getHeaders());
            }
        });

        // Database unreachable (connection refused, server gone away): 503, not a generic 500.
        // Any other database error falls through to the catch-all below.
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($request->is('api/*') && preg_match('/SQLSTATE\[HY000\] \[(2002|2006|2013)\]/', $e->getMessage())) {
                Log::error('api.database_unavailable', ['class' => get_class($e)]);

                return response()->json(['error' => [
                    'code' => 'SERVICE_UNAVAILABLE',
                    'message' => 'The service is temporarily unavailable. Please try again shortly.',
                ]], 503, ['Retry-After' => '30']);
            }
        });

        // Anything unexpected: log the details for us, show the client nothing sensitive.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') && ! $e instanceof HttpExceptionInterface) {
                Log::error('api.unhandled_exception', ['class' => get_class($e)]);

                return response()->json(['error' => [
                    'code' => 'SERVER_ERROR',
                    'message' => 'Something went wrong on our side.',
                ]], 500);
            }
        });
    })->create();