<?php

namespace App\Http\Middleware;

use App\Models\AuthToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $authToken = $token
            ? AuthToken::with('user')->where('token_hash', hash('sha256', $token))->first()
            : null;

        if (! $authToken) {
            return response()->json(['message' => 'Sesión no válida.'], 401);
        }

        $request->setUserResolver(fn () => $authToken->user);

        return $next($request);
    }
}
