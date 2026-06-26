<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_read_configured_vapid_public_key(): void
    {
        config(['services.webpush.vapid.public_key' => 'configured-public-key']);

        $this->actingAs(User::factory()->create())
            ->getJson(route('push-subscriptions.key'))
            ->assertOk()
            ->assertJsonPath('public_key', 'configured-public-key');
    }

    public function test_public_key_endpoint_reports_missing_configuration(): void
    {
        config(['services.webpush.vapid.public_key' => null]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('push-subscriptions.key'))
            ->assertStatus(503);
    }

    public function test_user_can_store_and_update_push_subscription_by_endpoint(): void
    {
        $user = User::factory()->create();
        $endpoint = 'https://push.example.test/subscriptions/device-one';

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), [
                'endpoint' => $endpoint,
                'expiration_time' => '2026-06-26T12:00:00.000Z',
                'keys' => [
                    'p256dh' => 'public-key-one',
                    'auth' => 'auth-token-one',
                ],
                'content_encoding' => 'aes128gcm',
                'user_agent' => 'Padik Test Browser',
            ])
            ->assertOk()
            ->assertJsonPath('enabled', true);

        $subscription = PushSubscription::query()->firstOrFail();

        $this->assertSame($user->id, $subscription->user_id);
        $this->assertSame($endpoint, $subscription->endpoint);
        $this->assertSame(PushSubscription::endpointHash($endpoint), $subscription->endpoint_hash);
        $this->assertSame('public-key-one', $subscription->public_key);
        $this->assertSame('auth-token-one', $subscription->auth_token);
        $this->assertSame('aes128gcm', $subscription->content_encoding);
        $this->assertNotNull($subscription->last_used_at);
        $this->assertSame('2026-06-26T12:00:00+00:00', $subscription->expires_at?->toIso8601String());

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), [
                'endpoint' => $endpoint,
                'keys' => [
                    'p256dh' => 'public-key-two',
                    'auth' => 'auth-token-two',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('enabled', true);

        $this->assertSame(1, PushSubscription::query()->count());

        $subscription->refresh();

        $this->assertSame('public-key-two', $subscription->public_key);
        $this->assertSame('auth-token-two', $subscription->auth_token);
        $this->assertSame('aes128gcm', $subscription->content_encoding);
        $this->assertNull($subscription->expires_at);
    }

    public function test_existing_endpoint_is_moved_to_the_current_authenticated_user(): void
    {
        $previousUser = User::factory()->create();
        $currentUser = User::factory()->create();
        $endpoint = 'https://push.example.test/subscriptions/shared-browser-device';

        PushSubscription::factory()->for($previousUser)->create([
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::endpointHash($endpoint),
            'public_key' => 'old-public-key',
            'auth_token' => 'old-auth-token',
        ]);

        $this->actingAs($currentUser)
            ->postJson(route('push-subscriptions.store'), [
                'endpoint' => $endpoint,
                'keys' => [
                    'p256dh' => 'new-public-key',
                    'auth' => 'new-auth-token',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('enabled', true);

        $this->assertSame(1, PushSubscription::query()->count());

        $subscription = PushSubscription::query()->firstOrFail();

        $this->assertSame($currentUser->id, $subscription->user_id);
        $this->assertSame('new-public-key', $subscription->public_key);
        $this->assertSame('new-auth-token', $subscription->auth_token);
    }

    public function test_user_can_keep_multiple_device_subscriptions_and_delete_one_endpoint(): void
    {
        $user = User::factory()->create();
        $firstEndpoint = 'https://push.example.test/subscriptions/device-one';
        $secondEndpoint = 'https://push.example.test/subscriptions/device-two';

        PushSubscription::factory()->for($user)->create([
            'endpoint' => $firstEndpoint,
            'endpoint_hash' => PushSubscription::endpointHash($firstEndpoint),
        ]);
        $secondSubscription = PushSubscription::factory()->for($user)->create([
            'endpoint' => $secondEndpoint,
            'endpoint_hash' => PushSubscription::endpointHash($secondEndpoint),
        ]);

        $this->actingAs($user)
            ->deleteJson(route('push-subscriptions.destroy'), [
                'endpoint' => $firstEndpoint,
            ])
            ->assertOk()
            ->assertJsonPath('enabled', false);

        $this->assertSame(1, $user->pushSubscriptions()->count());
        $this->assertModelExists($secondSubscription);
    }

    public function test_unsubscribe_does_not_delete_another_users_endpoint(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $endpoint = 'https://push.example.test/subscriptions/other-user-device';
        $otherSubscription = PushSubscription::factory()->for($otherUser)->create([
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::endpointHash($endpoint),
        ]);

        $this->actingAs($user)
            ->deleteJson(route('push-subscriptions.destroy'), [
                'endpoint' => $endpoint,
            ])
            ->assertOk()
            ->assertJsonPath('enabled', false);

        $this->assertModelExists($otherSubscription);
    }

    public function test_subscription_routes_require_authentication(): void
    {
        $this->getJson(route('push-subscriptions.key'))->assertUnauthorized();
        $this->postJson(route('push-subscriptions.store'))->assertUnauthorized();
        $this->deleteJson(route('push-subscriptions.destroy'))->assertUnauthorized();
    }

    public function test_vapid_generate_command_prints_env_ready_keys(): void
    {
        $this->artisan('push:vapid-generate', ['--subject' => 'mailto:admin@example.com'])
            ->expectsOutputToContain('VAPID_PUBLIC_KEY=')
            ->expectsOutputToContain('VAPID_PRIVATE_KEY=')
            ->expectsOutput('VAPID_SUBJECT=mailto:admin@example.com')
            ->assertSuccessful();
    }
}
