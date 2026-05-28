<?php

namespace Tests\Feature;

use App\Actions\Conversations\EnsureGeneralConversation;
use App\Enums\ConversationType;
use App\Events\RoomMessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\DirectMessageReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DirectMessageConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_open_a_direct_message_with_another_user(): void
    {
        $user = User::factory()->create();
        $recipient = User::factory()->create(['name' => 'Alice']);

        $this->actingAs($user)
            ->get(route('direct-messages.show', $recipient))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentRoom.title', 'Alice')
                ->where('currentRoom.type', ConversationType::Direct->value)
                ->where('currentRoom.direct_user_id', $recipient->id)
                ->has('messages', 0)
            );

        $conversation = Conversation::query()
            ->where('type', ConversationType::Direct)
            ->firstOrFail();

        $this->assertSame("dm-{$user->id}-{$recipient->id}", $conversation->slug);
        $this->assertTrue($conversation->users()->whereKey($user->id)->exists());
        $this->assertTrue($conversation->users()->whereKey($recipient->id)->exists());
    }

    public function test_opening_a_direct_message_reuses_the_existing_conversation(): void
    {
        $user = User::factory()->create();
        $recipient = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Direct,
            'title' => null,
            'slug' => 'dm-existing',
            'created_by_id' => $recipient->id,
        ]);
        $conversation->users()->attach([$user->id, $recipient->id]);

        $this->actingAs($user)
            ->get(route('direct-messages.show', $recipient))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentRoom.id', $conversation->id)
                ->where('currentRoom.slug', 'dm-existing')
            );

        $this->assertSame(1, Conversation::query()->where('type', ConversationType::Direct)->count());
    }

    public function test_direct_messages_are_loaded_and_can_be_sent(): void
    {
        Event::fake([RoomMessageSent::class]);

        $user = User::factory()->create();
        $recipient = User::factory()->create(['name' => 'Alice']);
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Direct,
            'title' => null,
            'slug' => 'dm-thread',
            'created_by_id' => $user->id,
        ]);
        $conversation->users()->attach([$user->id, $recipient->id]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $recipient->id,
            'body' => 'Hi from Alice.',
        ]);

        $this->actingAs($user)
            ->get(route('direct-messages.show', $recipient))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('messages', 1)
                ->where('messages.0.sender_id', $recipient->id)
                ->where('messages.0.body', 'Hi from Alice.')
                ->where('messages.0.own', false)
            );

        $this->actingAs($user)
            ->postJson(route('rooms.messages.store', ['conversation' => $conversation->slug]), [
                'body' => 'Private reply.',
            ])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Private reply.')
            ->assertJsonPath('message.own', true)
            ->assertJsonPath('conversation.id', $conversation->id)
            ->assertJsonPath('conversation.slug', 'dm-thread')
            ->assertJsonPath('conversation.type', ConversationType::Direct->value)
            ->assertJsonPath('conversation.direct_user_id', $recipient->id);

        Event::assertDispatched(
            RoomMessageSent::class,
            fn (RoomMessageSent $event): bool => $event->message->conversation_id === $conversation->id
                && $event->message->body === 'Private reply.'
                && $event->broadcastWith()['conversation']['id'] === $conversation->id
                && $event->broadcastWith()['conversation']['slug'] === 'dm-thread'
                && $event->broadcastWith()['conversation']['type'] === ConversationType::Direct->value
                && $event->broadcastWith()['conversation']['direct_user_id'] === $user->id
        );

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $recipient->id,
            'unread_count' => 1,
        ]);

        $this->actingAs($recipient)
            ->get(route('direct-messages.show', $user))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('directMessageUsers.0.unread_count', 0)
            );

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $recipient->id,
            'unread_count' => 0,
        ]);
    }

    public function test_sending_a_direct_message_creates_a_database_notification_for_the_recipient(): void
    {
        $user = User::factory()->create();
        $recipient = User::factory()->create(['name' => 'Alice']);
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Direct,
            'title' => null,
            'slug' => 'dm-notifications',
            'created_by_id' => $user->id,
        ]);
        $conversation->users()->attach([$user->id, $recipient->id]);

        $this->actingAs($user)
            ->postJson(route('rooms.messages.store', ['conversation' => $conversation->slug]), [
                'body' => 'This should notify Alice.',
            ])
            ->assertCreated();

        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(1, $recipient->unreadNotifications()->count());

        $notification = $recipient->notifications()->firstOrFail();

        $this->assertSame(DirectMessageReceived::class, $notification->type);
        $this->assertSame($user->id, $notification->data['sender_id']);
        $this->assertSame($user->name, $notification->data['sender_name']);
        $this->assertSame('This should notify Alice.', $notification->data['body']);
        $this->assertSame(route('direct-messages.show', $user), $notification->data['action_url']);
    }

    public function test_authenticated_layout_shares_notifications_and_can_mark_them_as_read(): void
    {
        $sender = User::factory()->create(['name' => 'Tom']);
        $recipient = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Direct,
            'title' => null,
            'slug' => 'dm-shared-notifications',
            'created_by_id' => $sender->id,
        ]);
        $conversation->users()->attach([$sender->id, $recipient->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $sender->id,
            'body' => 'Shared notification.',
        ]);

        $recipient->notify(new DirectMessageReceived($message));
        $this->app->make(EnsureGeneralConversation::class)->addUser($recipient);

        $this->actingAs($recipient)
            ->get(route('rooms.show', ['conversation' => 'general']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.unread_count', 1)
                ->has('notifications.items', 1)
                ->where('notifications.items.0.sender_name', 'Tom')
                ->where('notifications.items.0.body', 'Shared notification.')
                ->where('notifications.items.0.sender_id', $sender->id)
                ->where('notifications.items.0.action_url', route('direct-messages.show', $sender))
                ->where('notifications.items.0.read_at', null)
            );

        $this->actingAs($recipient)
            ->post(route('notifications.read'))
            ->assertRedirect();

        $this->assertSame(0, $recipient->fresh()->unreadNotifications()->count());
    }

    public function test_user_can_mark_notifications_from_a_specific_sender_as_read(): void
    {
        $sender = User::factory()->create();
        $otherSender = User::factory()->create();
        $recipient = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Direct,
            'title' => null,
            'slug' => 'dm-specific-sender-notifications',
            'created_by_id' => $sender->id,
        ]);
        $otherConversation = Conversation::factory()->create([
            'type' => ConversationType::Direct,
            'title' => null,
            'slug' => 'dm-other-sender-notifications',
            'created_by_id' => $otherSender->id,
        ]);
        $conversation->users()->attach([$sender->id, $recipient->id]);
        $otherConversation->users()->attach([$otherSender->id, $recipient->id]);

        $recipient->notify(new DirectMessageReceived(Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $sender->id,
            'body' => 'First unread.',
        ])));
        $recipient->notify(new DirectMessageReceived(Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $sender->id,
            'body' => 'Second unread.',
        ])));
        $recipient->notify(new DirectMessageReceived(Message::factory()->create([
            'conversation_id' => $otherConversation->id,
            'user_id' => $otherSender->id,
            'body' => 'Leave this unread.',
        ])));

        $this->actingAs($recipient)
            ->post(route('notifications.from-sender.read', $sender))
            ->assertRedirect();

        $this->assertSame(1, $recipient->fresh()->unreadNotifications()->count());
        $this->assertSame(
            0,
            $recipient->notifications()
                ->whereNull('read_at')
                ->where('data->sender_id', $sender->id)
                ->count()
        );
        $this->assertSame(
            1,
            $recipient->notifications()
                ->whereNull('read_at')
                ->where('data->sender_id', $otherSender->id)
                ->count()
        );
    }

    public function test_direct_message_navigation_uses_unread_notifications_as_unread_count(): void
    {
        $user = User::factory()->create();
        $sender = User::factory()->create(['name' => 'Alice']);
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Direct,
            'title' => null,
            'slug' => 'dm-notification-fallback',
            'created_by_id' => $sender->id,
        ]);
        $conversation->users()->attach([$user->id, $sender->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $sender->id,
            'body' => 'Unread notification body.',
        ]);

        $user->notify(new DirectMessageReceived($message));
        $this->app->make(EnsureGeneralConversation::class)->addUser($user);

        $this->actingAs($user)
            ->get(route('rooms.show', ['conversation' => 'general']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('directMessageUsers.0.id', $sender->id)
                ->where('directMessageUsers.0.unread_count', 1)
            );

        $this->actingAs($user)
            ->get(route('direct-messages.show', $sender))
            ->assertOk();

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    public function test_users_cannot_open_a_direct_message_with_themselves(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('direct-messages.show', $user))
            ->assertNotFound();
    }
}
