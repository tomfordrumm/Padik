<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_another_user_profile(): void
    {
        $user = User::factory()->create();
        $profileUser = User::factory()->create(['name' => 'Alice']);

        $this->actingAs($user)
            ->get(route('users.show', $profileUser))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('UserProfile')
                ->where('profileUser.id', $profileUser->id)
                ->where('profileUser.name', 'Alice')
            );
    }

    public function test_guests_are_redirected_from_user_profiles(): void
    {
        $profileUser = User::factory()->create();

        $this->get(route('users.show', $profileUser))
            ->assertRedirect(route('login'));
    }
}
