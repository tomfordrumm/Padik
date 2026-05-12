<?php

namespace Tests\Feature;

use App\Enums\ConversationParticipantRole;
use App\Enums\ConversationType;
use App\Enums\InvitationStatus;
use App\Enums\UserStatus;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Invitation;
use App\Models\Message;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MessengerModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_messenger_models_store_core_relationships(): void
    {
        $creator = User::factory()->create();
        $member = User::factory()->create([
            'status' => UserStatus::Active,
        ]);

        $profile = UserProfile::factory()->create([
            'user_id' => $member->id,
        ]);

        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'created_by_id' => $creator->id,
        ]);

        $participant = ConversationParticipant::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $member->id,
            'role' => ConversationParticipantRole::Owner,
            'unread_count' => 3,
            'last_read_at' => now(),
        ]);

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $member->id,
            'body' => 'Hello from the group room.',
        ]);

        $invitation = Invitation::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $creator->id,
            'user_id' => $member->id,
            'status' => InvitationStatus::Pending,
        ]);

        $this->assertTrue($member->profile->is($profile));
        $this->assertTrue($conversation->creator->is($creator));
        $this->assertTrue($conversation->participants->first()->is($participant));
        $this->assertTrue($conversation->users->first()->is($member));
        $this->assertTrue($conversation->messages->first()->is($message));
        $this->assertTrue($conversation->invitations->first()->is($invitation));
        $this->assertTrue($member->messages->first()->is($message));
        $this->assertTrue($creator->sentInvitations->first()->is($invitation));
        $this->assertTrue($member->receivedInvitations->first()->is($invitation));
        $this->assertSame(ConversationType::Group, $conversation->type);
        $this->assertSame(ConversationParticipantRole::Owner, $participant->role);
        $this->assertSame(InvitationStatus::Pending, $invitation->status);
    }

    public function test_laravel_database_notifications_are_available_on_users(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->assertCount(0, $user->notifications);
        $this->assertCount(0, $user->unreadNotifications);
    }
}
