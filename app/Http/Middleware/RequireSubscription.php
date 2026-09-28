<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $hasSubscription = $user->subscription_status === 'active';
        $trialActive = $user->trial_started_at
            && $user->trial_started_at->gt(now()->subMinutes(15));

        if (! $hasSubscription && ! $trialActive) {
            return response()->json([
                'message' => 'Tu prueba gratuita terminó. Elige un plan para continuar.',
                'code' => 'subscription_required',
            ], 402);
        }

        return $next($request);
    }
}
