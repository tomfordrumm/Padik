<?php

namespace Database\Factories;

use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement([ConversationType::Direct, ConversationType::Group]),
            'title' => fake()->optional()->sentence(3),
            'slug' => fake()->unique()->slug(3),
            'status' => ConversationStatus::Active,
            'created_by_id' => User::factory(),
        ];
    }

    public function general(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ConversationType::General,
            'title' => 'General',
            'slug' => 'general',
            'created_by_id' => null,
        ]);
    }

    public function secret(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ConversationType::Secret,
            'title' => null,
        ]);
    }
}
