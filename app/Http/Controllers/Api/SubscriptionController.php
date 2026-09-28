<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SubscriptionController
{
    public function access(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->trial_started_at && $user->subscription_status !== 'active') {
            $user->forceFill(['trial_started_at' => now()])->save();
            $user->refresh();
        }
        $trialEndsAt = $user->trial_started_at?->copy()->addMinutes(15);
        $trialActive = $trialEndsAt && $trialEndsAt->isFuture();

        return response()->json([
            'subscription_status' => $user->subscription_status,
            'plan' => $user->subscription_plan ?: 'basic',
            'plan_limits' => config('subscriptions.plans.'.($user->subscription_plan ?: 'basic')),
            'plans' => config('subscriptions.plans'),
            'trial_active' => $trialActive,
            'trial_ends_at' => $trialEndsAt,
            'can_access' => $user->subscription_status === 'active' || $trialActive,
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $plan = $request->validate([
            'plan' => ['required', 'in:basic,family,premium'],
        ])['plan'];
        $priceId = config("services.stripe.prices.{$plan}");
        $secret = config('services.stripe.secret');

        if (! $priceId || ! $secret || ! str_starts_with($priceId, 'price_')) {
            return response()->json(['message' => "El precio del plan {$plan} no está configurado con un price_id válido."], 503);
        }

        $user = $request->user();
        try {
            $session = Http::withBasicAuth($secret, '')
                ->withOptions(['verify' => config('services.stripe.ca_bundle')])
                ->asForm()
                ->post('https://api.stripe.com/v1/checkout/sessions', [
                    'mode' => 'subscription',
                    'line_items[0][price]' => $priceId,
                    'line_items[0][quantity]' => 1,
                    'customer_email' => $user->email,
                    'client_reference_id' => (string) $user->id,
                    'metadata[plan]' => $plan,
                    'subscription_data[metadata][plan]' => $plan,
                    'success_url' => config('app.frontend_url').'?subscription=success&session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => config('app.frontend_url').'?subscription=cancelled&plan='.$plan,
                ]);
        } catch (ConnectionException $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo conectar con Stripe. Verifica el certificado CA de PHP (curl.cainfo/openssl.cafile).',
            ], 502);
        }

        if ($session->failed()) {
            $stripeMessage = $session->json('error.message', 'Respuesta inválida de Stripe.');
            logger()->error('Stripe checkout rejected the request.', [
                'status' => $session->status(),
                'message' => $stripeMessage,
            ]);

            return response()->json([
                'message' => config('app.debug')
                    ? "Stripe rechazó el checkout: {$stripeMessage}"
                    : 'No se pudo iniciar el pago con Stripe.',
            ], 502);
        }

        return response()->json(['url' => $session->json('url')]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $sessionId = $request->validate([
            'session_id' => ['required', 'string', 'regex:/^cs_[a-zA-Z0-9_]+$/', 'max:255'],
        ])['session_id'];
        $session = $this->stripeGet('checkout/sessions/'.$sessionId);
        abort_unless((string) ($session['client_reference_id'] ?? '') === (string) $request->user()->id, 403);

        if (! $this->fulfillSession($session)) {
            return response()->json(['message' => 'Stripe todavía no confirma una suscripción activa.'], 409);
        }

        $request->user()->refresh();
        return $this->access($request);
    }

    private function stripeGet(string $path): array
    {
        abort_unless(config('services.stripe.secret'), 503, 'Stripe no está configurado.');
        try {
            $response = Http::withBasicAuth(config('services.stripe.secret'), '')
                ->withOptions(['verify' => config('services.stripe.ca_bundle')])
                ->timeout(20)->get('https://api.stripe.com/v1/'.$path);
        } catch (ConnectionException $exception) {
            report($exception);
            abort(502, 'No se pudo verificar el pago con Stripe.');
        }
        abort_unless($response->successful(), 502, 'No se pudo verificar el pago con Stripe.');
        return $response->json();
    }

    private function syncSubscription(User $user, array $subscription): bool
    {
        $priceId = $subscription['items']['data'][0]['price']['id'] ?? null;
        $plan = $priceId ? array_search($priceId, config('services.stripe.prices'), true) : false;
        $active = in_array($subscription['status'] ?? '', ['active', 'trialing'], true);
        $user->forceFill([
            'subscription_status' => $active && $plan !== false ? 'active' : 'inactive',
            'subscription_plan' => $plan !== false ? $plan : $user->subscription_plan,
            'stripe_customer_id' => $subscription['customer'] ?? $user->stripe_customer_id,
            'stripe_subscription_id' => $subscription['id'],
        ])->save();
        return $active && $plan !== false;
    }

    private function fulfillSession(array $session): bool
    {
        if (($session['mode'] ?? '') !== 'subscription' || ($session['status'] ?? '') !== 'complete'
            || ! in_array($session['payment_status'] ?? '', ['paid', 'no_payment_required'], true)
            || empty($session['subscription']) || empty($session['client_reference_id'])) {
            return false;
        }
        $user = User::find($session['client_reference_id']);
        if (! $user) return false;
        // Retrieve current state so delayed/repeated events cannot reactivate a cancelled subscription.
        $subscription = $this->stripeGet('subscriptions/'.$session['subscription']);
        if ($user->stripe_subscription_id && $user->stripe_subscription_id !== $subscription['id']) {
            $current = $this->stripeGet('subscriptions/'.$user->stripe_subscription_id);
            if (($current['created'] ?? 0) > ($subscription['created'] ?? 0)) return false;
        }
        return $this->syncSubscription($user, $subscription);
    }

    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');
        $secret = config('services.stripe.webhook_secret');
        [$timestamp, $signedPayload] = $this->signatureParts($signature);

        if (! $secret || ! $timestamp || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300
            || ! array_filter($signedPayload, fn ($candidate) => is_string($candidate)
                && hash_equals(hash_hmac('sha256', $timestamp.'.'.$payload, $secret), $candidate))) {
            return response()->json(['message' => 'Firma de webhook inválida.'], 400);
        }

        $event = json_decode($payload, true);
        $object = $event['data']['object'] ?? [];
        $userId = $object['client_reference_id'] ?? null;

        if (in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) && $userId) {
            $this->fulfillSession($object);
        }

        if (($event['type'] ?? '') === 'customer.subscription.deleted' && ! empty($object['id'])) {
            User::where('stripe_subscription_id', $object['id'])->update([
                'subscription_status' => 'inactive',
            ]);
        }

        if (in_array($event['type'] ?? '', ['customer.subscription.updated', 'customer.subscription.paused', 'customer.subscription.resumed'], true) && ! empty($object['id'])) {
            $user = User::where('stripe_subscription_id', $object['id'])->first();
            if ($user) $this->syncSubscription($user, $this->stripeGet('subscriptions/'.$object['id']));
        }

        return response()->json(['received' => true]);
    }

    private function signatureParts(string $signature): array
    {
        $timestamp = null;
        $signedPayload = [];
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);
            if ($key === 't') $timestamp = $value;
            if ($key === 'v1') $signedPayload[] = $value;
        }

        return [$timestamp, $signedPayload];
    }
}
