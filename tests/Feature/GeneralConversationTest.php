<?php

namespace Tests\Feature;

use App\Actions\Conversations\EnsureGeneralConversation;
use App\Enums\ConversationParticipantRole;
use App\Enums\ConversationType;
use App\Enums\InvitationStatus;
use App\Events\RoomMessageSent;
use App\Http\Resources\MessageData;
use App\Models\Conversation;
use App\Models\Invitation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\RoomInvitationReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GeneralConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_conversation_is_seeded_once_and_contains_existing_users(): void
    {
        $users = User::factory()->count(2)->create();

        $this->seed();
        $this->app->make(EnsureGeneralConversation::class)->addAllUsers();

        $general = Conversation::query()
            ->where('type', ConversationType::General)
            ->firstOrFail();

        $this->assertSame('General', $general->title);
        $this->assertSame('general', $general->slug);
        $this->assertSame(1, Conversation::query()->where('type', ConversationType::General)->count());

        $users->each(fn (User $user) => $this->assertTrue($general->users()->whereKey($user->id)->exists()));
    }

    public function test_authenticated_layout_shares_user_rooms(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->app->make(EnsureGeneralConversation::class)->addUser($user);
        $general = Conversation::query()->where('slug', 'general')->firstOrFail();

        Message::factory()->create([
            'conversation_id' => $general->id,
            'user_id' => $user->id,
            'body' => 'Latest room message.',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rooms', 1)
                ->where('rooms.0.title', 'General')
                ->where('rooms.0.slug', 'general')
                ->where('rooms.0.type', ConversationType::General->value)
                ->where('rooms.0.last_message', 'Latest room message.')
                ->has('directMessageUsers', 1)
                ->where('directMessageUsers.0.id', $otherUser->id)
            );
    }

    public function test_authenticated_layout_shares_latest_direct_message_for_each_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create(['name' => 'Alice']);
        $newUser = User::factory()->create(['name' => 'Bob']);
        $this->app->make(EnsureGeneralConversation::class)->addUser($user);

        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Direct,
            'title' => null,
            'created_by_id' => $user->id,
        ]);
        $conversation->users()->attach([$user->id, $otherUser->id]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'body' => 'Older direct message.',
            'created_at' => now()->subMinute(),
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $otherUser->id,
            'body' => 'Latest direct message.',
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rooms', 1)
                ->where('rooms.0.type', ConversationType::General->value)
                ->has('directMessageUsers', 2)
                ->where('directMessageUsers.0.id', $otherUser->id)
                ->where('directMessageUsers.0.name', 'Alice')
                ->where('directMessageUsers.0.last_message', 'Latest direct message.')
                ->where('directMessageUsers.1.id', $newUser->id)
                ->where('directMessageUsers.1.name', 'Bob')
                ->where('directMessageUsers.1.last_message', null)
            );
    }

    public function test_authenticated_users_can_open_a_room_by_slug(): void
    {
        $user = User::factory()->create();
        $this->app->make(EnsureGeneralConversation::class)->addUser($user);

        $this->actingAs($user)
            ->get('/r/general')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentRoom.title', 'General')
                ->where('currentRoom.slug', 'general')
                ->has('messages', 0)
            );
    }

    public function test_authenticated_users_can_create_group_rooms(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('rooms.store'), [
                'title' => 'Product Ideas',
            ])
            ->assertRedirect('/r/product-ideas');

        $conversation = Conversation::query()
            ->where('slug', 'product-ideas')
            ->firstOrFail();

        $this->assertSame(ConversationType::Group, $conversation->type);
        $this->assertSame('Product Ideas', $conversation->title);
        $this->assertSame($user->id, $conversation->created_by_id);
        $this->assertTrue(
            $conversation->users()
                ->whereKey($user->id)
                ->wherePivot('role', ConversationParticipantRole::Owner->value)
                ->exists()
        );
    }

    public function test_group_room_titles_are_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('rooms.store'), [
                'title' => '   ',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('title');
    }

    public function test_group_room_members_can_invite_users(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'title' => 'Product Ideas',
            'slug' => 'product-ideas',
            'created_by_id' => $owner->id,
        ]);
        $conversation->users()->attach($owner->id, [
            'role' => ConversationParticipantRole::Owner->value,
        ]);

        $this->actingAs($owner)
            ->post(route('rooms.invitations.store', $conversation), [
                'user_ids' => [$invitee->id],
            ])
            ->assertRedirect('/r/product-ideas');

        $this->assertDatabaseHas('invitations', [
            'conversation_id' => $conversation->id,
            'sender_id' => $owner->id,
            'user_id' => $invitee->id,
            'status' => InvitationStatus::Pending->value,
        ]);

        Notification::assertSentTo(
            $invitee,
            fn (RoomInvitationReceived $notification): bool => $notification->invitation->conversation_id === $conversation->id
                && $notification->invitation->sender_id === $owner->id
        );
    }

    public function test_group_room_invites_do_not_duplicate_pending_invitations(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'slug' => 'existing-room',
            'created_by_id' => $owner->id,
        ]);
        $conversation->users()->attach($owner->id, [
            'role' => ConversationParticipantRole::Owner->value,
        ]);

        Invitation::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $owner->id,
            'user_id' => $invitee->id,
            'status' => InvitationStatus::Pending,
        ]);

        $this->actingAs($owner)
            ->post(route('rooms.invitations.store', $conversation), [
                'user_ids' => [$invitee->id],
            ])
            ->assertRedirect('/r/existing-room');

        $this->assertSame(
            1,
            Invitation::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $invitee->id)
                ->where('status', InvitationStatus::Pending)
                ->count()
        );

        Notification::assertNotSentTo($invitee, RoomInvitationReceived::class);
    }

    public function test_group_room_members_cannot_invite_existing_members(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'slug' => 'existing-room',
            'created_by_id' => $owner->id,
        ]);
        $conversation->users()->attach([
            $owner->id => ['role' => ConversationParticipantRole::Owner->value],
            $member->id => ['role' => ConversationParticipantRole::Member->value],
        ]);

        $this->actingAs($owner)
            ->from(route('rooms.show', $conversation))
            ->post(route('rooms.invitations.store', $conversation), [
                'user_ids' => [$member->id],
            ])
            ->assertRedirect(route('rooms.show', $conversation))
            ->assertSessionHasErrors('user_ids.0');
    }

    public function test_users_can_accept_room_invitation_notifications(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'slug' => 'product-ideas',
            'created_by_id' => $owner->id,
        ]);
        $invitation = Invitation::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $owner->id,
            'user_id' => $invitee->id,
            'status' => InvitationStatus::Pending,
        ]);
        $invitee->notify(new RoomInvitationReceived($invitation));
        $notification = $invitee->notifications()->firstOrFail();

        $this->actingAs($invitee)
            ->post(route('notifications.invitations.accept', $notification))
            ->assertRedirect('/r/product-ideas');

        $this->assertTrue(
            $conversation->users()
                ->whereKey($invitee->id)
                ->wherePivot('role', ConversationParticipantRole::Member->value)
                ->exists()
        );
        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'status' => InvitationStatus::Accepted->value,
        ]);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_users_can_decline_room_invitation_notifications(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'slug' => 'product-ideas',
            'created_by_id' => $owner->id,
        ]);
        $invitation = Invitation::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $owner->id,
            'user_id' => $invitee->id,
            'status' => InvitationStatus::Pending,
        ]);
        $invitee->notify(new RoomInvitationReceived($invitation));
        $notification = $invitee->notifications()->firstOrFail();

        $this->actingAs($invitee)
            ->from(route('dashboard'))
            ->post(route('notifications.invitations.decline', $notification))
            ->assertRedirect(route('dashboard'));

        $this->assertFalse($conversation->users()->whereKey($invitee->id)->exists());
        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'status' => InvitationStatus::Declined->value,
        ]);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_group_room_creators_can_open_settings_and_rename_room(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['name' => 'Member User']);
        $pendingUser = User::factory()->create(['name' => 'Pending User']);
        $availableUser = User::factory()->create(['name' => 'Available User']);
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'title' => 'Old Name',
            'slug' => 'old-name',
            'created_by_id' => $owner->id,
        ]);
        $conversation->users()->attach([
            $owner->id => ['role' => ConversationParticipantRole::Owner->value],
            $member->id => ['role' => ConversationParticipantRole::Member->value],
        ]);
        Invitation::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $owner->id,
            'user_id' => $pendingUser->id,
            'status' => InvitationStatus::Pending,
        ]);

        $this->actingAs($owner)
            ->get(route('rooms.settings.edit', $conversation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('RoomSettings')
                ->where('room.title', 'Old Name')
                ->where('room.slug', 'old-name')
                ->has('members', 2)
                ->has('pendingInvitations', 1)
                ->where('pendingInvitations.0.name', 'Pending User')
                ->has('availableUsers', 1)
                ->where('availableUsers.0.id', $availableUser->id)
            );

        $this->actingAs($owner)
            ->patch(route('rooms.update', $conversation), [
                'title' => 'New Name',
            ])
            ->assertRedirect('/r/old-name');

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'title' => 'New Name',
        ]);
    }

    public function test_group_room_creators_can_remove_members(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'created_by_id' => $owner->id,
        ]);
        $conversation->users()->attach([
            $owner->id => ['role' => ConversationParticipantRole::Owner->value],
            $member->id => ['role' => ConversationParticipantRole::Member->value],
        ]);

        $this->actingAs($owner)
            ->from(route('rooms.settings.edit', $conversation))
            ->delete(route('rooms.members.destroy', [$conversation, $member]))
            ->assertRedirect(route('rooms.settings.edit', $conversation));

        $this->assertFalse($conversation->users()->whereKey($member->id)->exists());
        $this->assertTrue($conversation->users()->whereKey($owner->id)->exists());
    }

    public function test_group_room_creators_can_cancel_pending_invitations(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'created_by_id' => $owner->id,
        ]);
        $conversation->users()->attach($owner->id, [
            'role' => ConversationParticipantRole::Owner->value,
        ]);
        $invitation = Invitation::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $owner->id,
            'user_id' => $invitee->id,
            'status' => InvitationStatus::Pending,
        ]);

        $this->actingAs($owner)
            ->from(route('rooms.settings.edit', $conversation))
            ->delete(route('rooms.invitations.destroy', [$conversation, $invitation]))
            ->assertRedirect(route('rooms.settings.edit', $conversation));

        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'status' => InvitationStatus::Cancelled->value,
        ]);
    }

    public function test_non_creators_cannot_manage_group_room_settings(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'created_by_id' => $owner->id,
        ]);
        $conversation->users()->attach([
            $owner->id => ['role' => ConversationParticipantRole::Owner->value],
            $member->id => ['role' => ConversationParticipantRole::Member->value],
        ]);

        $this->actingAs($member)
            ->get(route('rooms.settings.edit', $conversation))
            ->assertNotFound();

        $this->actingAs($member)
            ->patch(route('rooms.update', $conversation), [
                'title' => 'Unauthorized Name',
            ])
            ->assertForbidden();
    }

    public function test_group_room_members_can_leave_room(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'created_by_id' => $owner->id,
        ]);
        $conversation->users()->attach([
            $owner->id => ['role' => ConversationParticipantRole::Owner->value],
            $member->id => ['role' => ConversationParticipantRole::Member->value],
        ]);

        $this->actingAs($member)
            ->delete(route('rooms.membership.destroy', $conversation))
            ->assertRedirect(route('dashboard'));

        $this->assertFalse($conversation->users()->whereKey($member->id)->exists());
        $this->assertTrue($conversation->users()->whereKey($owner->id)->exists());
    }

    public function test_group_room_creators_cannot_leave_their_room(): void
    {
        $owner = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'created_by_id' => $owner->id,
        ]);
        $conversation->users()->attach($owner->id, [
            'role' => ConversationParticipantRole::Owner->value,
        ]);

        $this->actingAs($owner)
            ->delete(route('rooms.membership.destroy', $conversation))
            ->assertNotFound();
    }

    public function test_room_messages_are_loaded_from_the_database(): void
    {
        $user = User::factory()->create();
        $this->app->make(EnsureGeneralConversation::class)->addUser($user);
        $general = Conversation::query()->where('slug', 'general')->firstOrFail();

        Message::factory()->create([
            'conversation_id' => $general->id,
            'user_id' => $user->id,
            'body' => 'Hello General.',
        ]);

        $this->actingAs($user)
            ->get('/r/general')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('messages', 1)
                ->where('messages.0.sender_id', $user->id)
                ->where('messages.0.body', 'Hello General.')
                ->where('messages.0.own', true)
            );
    }

    public function test_own_messages_are_still_marked_after_a_new_login_session(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $generalConversation = $this->app->make(EnsureGeneralConversation::class);
        $generalConversation->addUser($user);
        $generalConversation->addUser($otherUser);
        $general = Conversation::query()->where('slug', 'general')->firstOrFail();

        Message::factory()->create([
            'conversation_id' => $general->id,
            'user_id' => $user->id,
            'body' => 'My persisted message.',
        ]);

        Message::factory()->create([
            'conversation_id' => $general->id,
            'user_id' => $otherUser->id,
            'body' => 'Another sender message.',
        ]);

        $this->post(route('logout'));

        $this->actingAs($user)
            ->get('/r/general')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('messages.0.sender_id', $user->id)
                ->where('messages.0.own', true)
                ->where('messages.1.sender_id', $otherUser->id)
                ->where('messages.1.own', false)
            );
    }

    public function test_message_payload_marks_own_relative_to_the_viewer(): void
    {
        $sender = User::factory()->create();
        $viewer = User::factory()->create();
        $message = Message::factory()->create([
            'user_id' => $sender->id,
        ]);

        $this->assertTrue(MessageData::fromMessage($message, $sender)['own']);
        $this->assertFalse(MessageData::fromMessage($message, $viewer)['own']);
        $this->assertFalse(MessageData::fromMessage($message)['own']);
    }

    public function test_users_can_send_messages_to_rooms_they_belong_to(): void
    {
        $user = User::factory()->create();
        $this->app->make(EnsureGeneralConversation::class)->addUser($user);

        $this->actingAs($user)
            ->post('/r/general/messages', [
                'body' => 'Posted from the composer.',
            ])
            ->assertRedirect('/r/general');

        $this->assertDatabaseHas('messages', [
            'user_id' => $user->id,
            'body' => 'Posted from the composer.',
        ]);
    }

    public function test_users_can_send_messages_with_json_response(): void
    {
        $user = User::factory()->create();
        $this->app->make(EnsureGeneralConversation::class)->addUser($user);
        $general = Conversation::query()->where('slug', 'general')->firstOrFail();

        $this->actingAs($user)
            ->postJson('/r/general/messages', [
                'body' => 'Posted without a page visit.',
            ])
            ->assertCreated()
            ->assertJsonPath('message.sender_id', $user->id)
            ->assertJsonPath('message.author', $user->name)
            ->assertJsonPath('message.body', 'Posted without a page visit.')
            ->assertJsonPath('message.own', true)
            ->assertJsonPath('conversation.id', $general->id)
            ->assertJsonPath('conversation.slug', 'general')
            ->assertJsonPath('conversation.type', ConversationType::General->value)
            ->assertJsonPath('conversation.direct_user_id', null);

        $this->assertDatabaseHas('messages', [
            'user_id' => $user->id,
            'body' => 'Posted without a page visit.',
        ]);
    }

    public function test_sending_a_room_message_broadcasts_it_to_room_members(): void
    {
        Event::fake([RoomMessageSent::class]);

        $user = User::factory()->create();
        $recipient = User::factory()->create();
        $this->app->make(EnsureGeneralConversation::class)->addUser($user);
        $this->app->make(EnsureGeneralConversation::class)->addUser($recipient);
        $general = Conversation::query()->where('slug', 'general')->firstOrFail();

        $this->actingAs($user)
            ->postJson('/r/general/messages', [
                'body' => 'Broadcast me.',
            ])
            ->assertCreated();

        Event::assertDispatched(
            RoomMessageSent::class,
            fn (RoomMessageSent $event): bool => $event->message->conversation_id === $general->id
                && $event->message->user_id === $user->id
                && $event->message->body === 'Broadcast me.'
                && $event->broadcastWith()['conversation']['id'] === $general->id
                && $event->broadcastWith()['conversation']['slug'] === 'general'
                && $event->broadcastWith()['conversation']['type'] === ConversationType::General->value
                && $event->broadcastWith()['conversation']['direct_user_id'] === null
        );

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $general->id,
            'user_id' => $recipient->id,
            'unread_count' => 1,
        ]);

        $this->actingAs($recipient)
            ->get(route('rooms.show', $general))
            ->assertOk();

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $general->id,
            'user_id' => $recipient->id,
            'unread_count' => 0,
        ]);
    }

    public function test_users_cannot_authorize_room_broadcast_channels_they_do_not_belong_to(): void
    {
        config(['broadcasting.default' => 'reverb']);

        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $room = Conversation::factory()->create([
            'created_by_id' => $member->id,
        ]);
        $room->users()->attach($member->id);

        $this->actingAs($outsider)
            ->post('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => "private-rooms.{$room->id}",
            ])
            ->assertForbidden();
    }

    public function test_users_cannot_send_messages_to_rooms_they_do_not_belong_to(): void
    {
        $user = User::factory()->create();
        $room = Conversation::factory()->create([
            'title' => 'Private Room',
            'slug' => 'private-room',
        ]);

        $this->actingAs($user)
            ->post("/r/{$room->slug}/messages", [
                'body' => 'No access.',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $room->id,
            'body' => 'No access.',
        ]);
    }

    public function test_users_cannot_open_rooms_they_do_not_belong_to(): void
    {
        $user = User::factory()->create();
        $room = Conversation::factory()->create([
            'title' => 'Private Room',
            'slug' => 'private-room',
        ]);

        $this->actingAs($user)
            ->get("/r/{$room->slug}")
            ->assertNotFound();
    }
}
