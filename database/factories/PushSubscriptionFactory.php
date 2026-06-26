<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $endpoint = fake()->unique()->url().'/push/'.fake()->uuid();

        return [
            'user_id' => User::factory(),
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::endpointHash($endpoint),
            'public_key' => fake()->regexify('[A-Za-z0-9_-]{88}'),
            'auth_token' => fake()->regexify('[A-Za-z0-9_-]{24}'),
            'content_encoding' => 'aes128gcm',
            'user_agent' => fake()->userAgent(),
            'last_used_at' => null,
            'expires_at' => null,
        ];
    }
}
