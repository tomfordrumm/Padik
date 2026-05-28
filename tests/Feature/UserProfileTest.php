<?php

namespace Tests\Feature;

use App\Enums\ConversationParticipantRole;
use App\Enums\ConversationType;
use App\Enums\InvitationStatus;
use App\Models\Conversation;
use App\Models\Invitation;
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
                ->has('invitableRooms', 0)
            );
    }

    public function test_profile_shares_only_group_rooms_the_user_can_invite_to(): void
    {
        $user = User::factory()->create();
        $profileUser = User::factory()->create(['name' => 'Alice']);
        $otherUser = User::factory()->create();

        $availableRoom = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'title' => 'Product Ideas',
            'slug' => 'product-ideas',
            'created_by_id' => $user->id,
        ]);
        $alreadyJoinedRoom = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'title' => 'Already Joined',
            'slug' => 'already-joined',
            'created_by_id' => $user->id,
        ]);
        $pendingRoom = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'title' => 'Pending Invite',
            'slug' => 'pending-invite',
            'created_by_id' => $user->id,
        ]);
        $generalRoom = Conversation::factory()->general()->create();
        $otherUsersRoom = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'title' => 'Other Users Room',
            'slug' => 'other-users-room',
            'created_by_id' => $otherUser->id,
        ]);

        $availableRoom->users()->attach($user->id, [
            'role' => ConversationParticipantRole::Member->value,
        ]);
        $alreadyJoinedRoom->users()->attach([
            $user->id => ['role' => ConversationParticipantRole::Member->value],
            $profileUser->id => ['role' => ConversationParticipantRole::Member->value],
        ]);
        $pendingRoom->users()->attach($user->id, [
            'role' => ConversationParticipantRole::Member->value,
        ]);
        $generalRoom->users()->attach([$user->id, $profileUser->id]);
        $otherUsersRoom->users()->attach($otherUser->id);

        Invitation::factory()->create([
            'conversation_id' => $pendingRoom->id,
            'sender_id' => $user->id,
            'user_id' => $profileUser->id,
            'status' => InvitationStatus::Pending,
        ]);

        $this->actingAs($user)
            ->get(route('users.show', $profileUser))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('invitableRooms', 1)
                ->where('invitableRooms.0.id', $availableRoom->id)
                ->where('invitableRooms.0.title', 'Product Ideas')
                ->where('invitableRooms.0.slug', 'product-ideas')
            );
    }

    public function test_guests_are_redirected_from_user_profiles(): void
    {
        $profileUser = User::factory()->create();

        $this->get(route('users.show', $profileUser))
            ->assertRedirect(route('login'));
    }
}
