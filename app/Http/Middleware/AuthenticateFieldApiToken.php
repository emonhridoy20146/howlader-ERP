<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token auth for the field integration API. The token itself is never stored:
 * config holds its SHA-256. A valid token logs the dedicated integration user in for
 * this request only, so existing services (PaymentService, audit logs) see a real actor.
 */
class AuthenticateFieldApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('field_api.enabled')) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $token = (string) $request->bearerToken();
        $hashes = (array) config('field_api.token_sha256', []);
        $userId = config('field_api.integration_user_id');

        $valid = $token !== '' && $hashes !== [] && collect($hashes)
            ->contains(fn ($h) => is_string($h) && hash_equals(strtolower($h), hash('sha256', $token)));

        if (! $valid || ! $userId) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => ['code' => 'UNAUTHENTICATED']], 401);
        }

        $user = User::query()->whereKey($userId)->where('is_active', true)->first();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Integration user is missing or inactive.', 'error' => ['code' => 'UNAUTHENTICATED']], 401);
        }

        Auth::onceUsingId($user->id);
        $requestId = substr((string) $request->header('X-Request-Id', ''), 0, 64);
        Log::withContext(['field_api_request_id' => $requestId]);

        $response = $next($request);
        if ($requestId !== '') {
            $response->headers->set('X-Request-Id', $requestId);
        }

        return $response;
    }
}
