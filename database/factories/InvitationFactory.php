<?php

namespace Database\Factories;

use App\Enums\InvitationStatus;
use App\Models\Conversation;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'sender_id' => User::factory(),
            'user_id' => User::factory(),
            'status' => InvitationStatus::Pending,
            'responded_at' => null,
        ];
    }
}
