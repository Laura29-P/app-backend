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
        $returnUrl = $request->input('client') === 'android'
            ? rtrim(config('app.url'), '/').'/api/billing/android-return'
            : config('app.frontend_url');
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
                    'success_url' => $returnUrl.'?subscription=success&session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => $returnUrl.'?subscription=cancelled&plan='.$plan,
                ]);
        } catch (ConnectionException $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo conectar con Stripe. Verifica el certificado CA de PHP (curl.cainfo/openssl.cafile).',
            ], 502);
        }

        if ($session->failed()) {
            $code = $session->json('error.code');
            $param = $session->json('error.param');
            $reference = (string) \Illuminate\Support\Str::uuid();
            [$reason, $message] = match (true) {
                $session->status() === 401 => ['stripe_auth', 'Stripe rechazó la clave del servidor. Revisa STRIPE_SECRET en Render.'],
                $session->status() === 403 => ['stripe_permissions', 'La clave de Stripe no tiene permisos para crear el pago. Revisa sus permisos en Stripe.'],
                in_array($param, ['success_url', 'cancel_url'], true) => ['stripe_return_url', 'Stripe rechazó la dirección de regreso. Revisa APP_URL para Android y FRONTEND_URL para la web en Render.'],
                is_string($param) && str_starts_with($param, 'line_items') => ['stripe_price', 'Stripe rechazó el precio del plan. Revisa que el precio sea recurrente y pertenezca a la misma cuenta y modo que STRIPE_SECRET.'],
                $param === 'customer_email' => ['stripe_email', 'Stripe rechazó el correo de tu cuenta. Revisa que sea una dirección válida.'],
                $session->status() === 429 => ['stripe_rate_limit', 'Stripe recibió demasiadas solicitudes. Espera un momento e inténtalo de nuevo.'],
                $session->status() >= 500 => ['stripe_unavailable', 'Stripe no está disponible temporalmente. Inténtalo de nuevo en unos minutos.'],
                default => ['stripe_rejected', 'Stripe rechazó la configuración del pago. Referencia: '.$reference],
            };
            logger()->error('Stripe checkout rejected the request.', [
                'status' => $session->status(),
                'code' => $code,
                'param' => $param,
                'reason' => $reason,
                'reference' => $reference,
                'stripe_request_id' => $session->header('Request-Id'),
                'plan' => $plan,
            ]);

            return response()->json([
                'message' => $message,
                'code' => $reason,
                'reference' => $reference,
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
