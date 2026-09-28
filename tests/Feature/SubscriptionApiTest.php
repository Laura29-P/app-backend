<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => 'sk_test_fake', 'services.stripe.webhook_secret' => 'whsec_test',
            'services.stripe.prices' => ['basic' => 'price_basic', 'family' => 'price_family', 'premium' => 'price_premium']]);
        Http::preventStrayRequests();
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test', 'email' => 'billing@example.com', 'password' => 'password123',
        ]);
        $this->withToken($response->json('token'));
        $this->user = User::firstOrFail();
        $this->user->forceFill(['trial_started_at' => now()->subHour()])->save();
    }

    private function checkoutSession(array $overrides = []): array
    {
        return array_replace([
            'id' => 'cs_test_paid', 'client_reference_id' => (string) $this->user->id,
            'mode' => 'subscription', 'status' => 'complete', 'payment_status' => 'paid',
            'subscription' => 'sub_test', 'metadata' => ['plan' => 'basic'],
        ], $overrides);
    }

    private function stripe(array $session = [], array $subscription = []): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_test_paid' => Http::response($this->checkoutSession($session)),
            'api.stripe.com/v1/subscriptions/sub_test' => Http::response(array_replace([
                'id' => 'sub_test', 'customer' => 'cus_test', 'status' => 'active',
                'items' => ['data' => [['price' => ['id' => 'price_premium']]]],
            ], $subscription)),
        ]);
    }

    private function webhook(string $type, array $object)
    {
        $payload = json_encode(['type' => $type, 'data' => ['object' => $object]]);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');
        return $this->call('POST', '/api/billing/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature},v1=old_signature",
        ], $payload);
    }

    public function test_return_confirms_paid_plan_without_waiting_for_webhook(): void
    {
        $this->stripe();
        $this->getJson('/api/account/access')->assertJsonPath('can_access', false);
        $this->postJson('/api/billing/confirm', ['session_id' => 'cs_test_paid'])
            ->assertOk()->assertJsonPath('plan', 'premium')->assertJsonPath('can_access', true)
            ->assertJsonPath('plan_limits.max_exercises_per_subject', 10)
            ->assertJsonPath('plan_limits.subjects.2', 'geo');
        $this->getJson('/api/characters?client_id=billing-test')->assertOk();
        $this->postJson('/api/billing/confirm', ['session_id' => 'cs_test_paid'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $this->user->id, 'subscription_plan' => 'premium']);
    }

    public function test_other_users_session_cannot_unlock_access(): void
    {
        $this->stripe(['client_reference_id' => '999999']);
        $this->postJson('/api/billing/confirm', ['session_id' => 'cs_test_paid'])->assertForbidden();
        $this->getJson('/api/account/access')->assertJsonPath('can_access', false);
    }

    public function test_unpaid_checkout_does_not_unlock_access(): void
    {
        $this->stripe(['payment_status' => 'unpaid']);
        $this->postJson('/api/billing/confirm', ['session_id' => 'cs_test_paid'])->assertStatus(409);
        $this->webhook('checkout.session.completed', $this->checkoutSession(['payment_status' => 'unpaid']))->assertOk();
        $this->getJson('/api/account/access')->assertJsonPath('can_access', false);
    }

    public function test_delayed_payment_webhook_activates_and_is_repeatable(): void
    {
        $this->stripe();
        $this->webhook('checkout.session.async_payment_succeeded', $this->checkoutSession())->assertOk();
        $this->webhook('checkout.session.async_payment_succeeded', $this->checkoutSession())->assertOk();
        $this->getJson('/api/account/access')->assertJsonPath('plan', 'premium')->assertJsonPath('can_access', true);
    }

    public function test_cancelled_subscription_cannot_be_reactivated_by_return(): void
    {
        $this->stripe([], ['status' => 'canceled']);
        $this->postJson('/api/billing/confirm', ['session_id' => 'cs_test_paid'])->assertStatus(409);
        $this->getJson('/api/account/access')->assertJsonPath('can_access', false);
    }

    public function test_plan_updates_use_current_price_even_without_metadata(): void
    {
        $this->user->forceFill(['stripe_subscription_id' => 'sub_test'])->save();
        $this->stripe([], ['items' => ['data' => [['price' => ['id' => 'price_family']]]]]);
        $this->webhook('customer.subscription.updated', ['id' => 'sub_test'])->assertOk();
        $this->getJson('/api/account/access')->assertJsonPath('plan', 'family')->assertJsonPath('can_access', true);
        $this->webhook('customer.subscription.deleted', ['id' => 'sub_test'])->assertOk();
        $this->getJson('/api/account/access')->assertJsonPath('can_access', false);
    }

    public function test_invalid_signature_and_missing_session_are_rejected(): void
    {
        $this->postJson('/api/billing/webhook', [])->assertStatus(400);
        $this->postJson('/api/billing/confirm', ['plan' => 'premium'])->assertUnprocessable();
    }

    public function test_stripe_failure_can_be_retried_without_granting_access(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response([], 503)]);
        $this->postJson('/api/billing/confirm', ['session_id' => 'cs_test_paid'])->assertStatus(502);
        $this->getJson('/api/account/access')->assertJsonPath('can_access', false);
    }

    public function test_unknown_price_does_not_grant_access_from_metadata(): void
    {
        $this->stripe([], ['items' => ['data' => [['price' => ['id' => 'price_unknown']]]], 'metadata' => ['plan' => 'premium']]);
        $this->postJson('/api/billing/confirm', ['session_id' => 'cs_test_paid'])->assertStatus(409);
        $this->getJson('/api/account/access')->assertJsonPath('can_access', false);
    }

    public function test_checkout_includes_session_id_in_return_url(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/test'])]);
        $this->postJson('/api/billing/checkout', ['plan' => 'family'])->assertOk();
        Http::assertSent(fn ($request) => $request['line_items[0][price]'] === 'price_family'
            && str_contains($request['success_url'], 'session_id={CHECKOUT_SESSION_ID}'));
    }

    public function test_android_checkout_returns_to_the_backend_bridge(): void
    {
        config(['app.url' => 'https://api.example.com']);
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/test'])]);
        $this->postJson('/api/billing/checkout', ['plan' => 'family', 'client' => 'android'])->assertOk();
        Http::assertSent(fn ($request) => $request['success_url'] === 'https://api.example.com/api/billing/android-return?subscription=success&session_id={CHECKOUT_SESSION_ID}');
        $this->get('/api/billing/android-return?subscription=success&session_id=cs_test_paid')
            ->assertOk()->assertSee('aventurakids://billing/return', false);
        $this->getJson('/api/billing/android-return?subscription=success&session_id=invalid')->assertUnprocessable();
        $this->getJson('/api/account/access')->assertJsonPath('can_access', false);
    }
}
