<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    /**
     * Authenticate a single deployment token. This API has no per-user accounts:
     * a valid token may call every endpoint for the employees stored here.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('services.workstate.token');
        $provided = (string) $request->bearerToken();

        if ($configured === '' || $provided === '' || ! hash_equals($configured, $provided)) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return $next($request);
    }
}
