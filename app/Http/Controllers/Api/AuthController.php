<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        // Server-side validation. Never rely on the Flutter form alone.
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // One generic answer for "no such user" and "wrong password", so the API
        // does not reveal which email addresses exist. The email is not logged.
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            Log::warning('auth.login_failed', ['reason' => 'invalid_credentials']);

            return response()->json([
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'Email or password is incorrect.',
                ],
            ], 401);
        }

        $token = $user->createToken('flutter-web')->plainTextToken;

        AuditLog::record($user->id, 'auth.login', 'user', $user->id);
        Log::info('auth.login', ['user_id' => $user->id, 'role' => $user->role]);

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    public function logout(Request $request): Response
    {
        $user = $request->user();

        // Revoke only the token used for this request.
        $user->currentAccessToken()->delete();

        AuditLog::record($user->id, 'auth.logout', 'user', $user->id);
        Log::info('auth.logout', ['user_id' => $user->id]);

        return response()->noContent();
    }

    /** Data minimization: id, name and role only. No email, no password hash. */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
        ];
    }
}
