<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role-based access control.
 * Usage on a route: ->middleware(EnsureRole::class.':librarian,supervisor')
 *
 * The check runs on the server for every request. Hiding a button in Flutter is a convenience,
 * never the protection.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles, true)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You are not allowed to perform this action.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
