<?php

namespace Database\Factories;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\SecretMessageDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecretMessageDelivery>
 */
class SecretMessageDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) fake()->uuid(),
            'conversation_id' => Conversation::factory()->state([
                'type' => ConversationType::Secret,
            ]),
            'sender_id' => User::factory(),
            'recipient_id' => User::factory(),
            'ciphertext' => fake()->sha256(),
            'iv' => fake()->regexify('[A-Za-z0-9+/]{16}'),
            'sender_fingerprint' => str_repeat('f', 64),
            'delivered_at' => null,
            'read_at' => null,
            'expires_at' => null,
        ];
    }
}
