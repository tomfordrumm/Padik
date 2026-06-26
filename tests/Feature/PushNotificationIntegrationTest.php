<?php

namespace Tests\Feature;

use App\Actions\Conversations\EnsureGeneralConversation;
use App\Enums\ConversationType;
use App\Enums\InvitationStatus;
use App\Events\RoomMessageSent;
use App\Events\SecretChatMessageSent;
use App\Jobs\SendWebPushNotification;
use App\Models\Conversation;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\DirectMessageReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PushNotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_message_push_payload_is_private_and_queued(): void
    {
        $sender = User::factory()->create(['name' => 'Tom']);
        $recipient = User::factory()->create(['name' => 'Alice']);
        $conversation = $this->directConversation($sender, $recipient, 'dm-push-private');

        Event::fake([RoomMessageSent::class]);
        Queue::fake([SendWebPushNotification::class]);

        $this->actingAs($sender)
            ->postJson(route('rooms.messages.store', ['conversation' => $conversation->slug]), [
                'body' => 'plaintext body must stay out of OS push',
            ])
            ->assertCreated();

        Queue::assertPushed(
            SendWebPushNotification::class,
            fn (SendWebPushNotification $job): bool => $job->recipientId === $recipient->id
                && $job->payload['type'] === 'direct_message'
                && $job->payload['title'] === 'New direct message'
                && $job->payload['body'] === 'Tom sent you a message'
                && ! str_contains($job->payload['body'], 'plaintext body')
                && $job->payload['conversation_id'] === $conversation->id
                && $job->payload['sender_id'] === $sender->id
                && $job->payload['action_url'] === route('direct-messages.show', $sender)
        );

        Queue::assertNotPushed(
            SendWebPushNotification::class,
            fn (SendWebPushNotification $job): bool => $job->recipientId === $sender->id
        );

        $notification = $recipient->notifications()->firstOrFail();

        $this->assertSame(DirectMessageReceived::class, $notification->type);
        $this->assertSame('plaintext body must stay out of OS push', $notification->data['body']);
    }

    public function test_active_direct_conversation_suppresses_only_os_push(): void
    {
        $sender = User::factory()->create(['name' => 'Tom']);
        $recipient = User::factory()->create(['name' => 'Alice']);
        $conversation = $this->directConversation($sender, $recipient, 'dm-push-active');

        Event::fake([RoomMessageSent::class]);
        Queue::fake([SendWebPushNotification::class]);

        $this->actingAs($recipient)
            ->postJson(route('push-presence.store'), [
                'conversation_id' => $conversation->id,
            ])
            ->assertOk()
            ->assertJsonPath('active', true);

        $this->actingAs($sender)
            ->postJson(route('rooms.messages.store', ['conversation' => $conversation->slug]), [
                'body' => 'in-app notification still exists',
            ])
            ->assertCreated();

        Queue::assertNotPushed(SendWebPushNotification::class);

        $this->assertSame(1, $recipient->unreadNotifications()->count());
    }

    public function test_expired_presence_allows_direct_message_push(): void
    {
        $sender = User::factory()->create(['name' => 'Tom']);
        $recipient = User::factory()->create(['name' => 'Alice']);
        $conversation = $this->directConversation($sender, $recipient, 'dm-push-expired');

        Event::fake([RoomMessageSent::class]);
        Queue::fake([SendWebPushNotification::class]);

        $this->actingAs($recipient)
            ->postJson(route('push-presence.store'), [
                'conversation_id' => $conversation->id,
            ])
            ->assertOk();

        $this->travel(91)->seconds();

        $this->actingAs($sender)
            ->postJson(route('rooms.messages.store', ['conversation' => $conversation->slug]), [
                'body' => 'presence expired',
            ])
            ->assertCreated();

        Queue::assertPushed(
            SendWebPushNotification::class,
            fn (SendWebPushNotification $job): bool => $job->recipientId === $recipient->id
                && $job->payload['type'] === 'direct_message'
        );
    }

    public function test_mention_push_payload_is_private_and_queued(): void
    {
        $sender = User::factory()->create(['name' => 'Tom']);
        $mentioned = User::factory()->create(['name' => 'Alice']);
        $generalConversation = $this->app->make(EnsureGeneralConversation::class);
        $generalConversation->addUser($sender);
        $generalConversation->addUser($mentioned);
        $general = Conversation::query()->where('slug', 'general')->firstOrFail();

        Event::fake([RoomMessageSent::class]);
        Queue::fake([SendWebPushNotification::class]);

        $this->actingAs($sender)
            ->postJson(route('rooms.messages.store', ['conversation' => $general->slug]), [
                'body' => 'secret mention body @Alice',
            ])
            ->assertCreated();

        Queue::assertPushed(
            SendWebPushNotification::class,
            fn (SendWebPushNotification $job): bool => $job->recipientId === $mentioned->id
                && $job->payload['type'] === 'mention'
                && $job->payload['body'] === 'Tom mentioned you in General'
                && ! str_contains($job->payload['body'], 'secret mention body')
                && $job->payload['conversation_id'] === $general->id
        );
    }

    public function test_room_invitation_push_payload_is_private_and_queued(): void
    {
        $sender = User::factory()->create(['name' => 'Tom']);
        $recipient = User::factory()->create(['name' => 'Alice']);
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Group,
            'title' => 'Launch room',
            'slug' => 'launch-room',
            'created_by_id' => $sender->id,
        ]);
        $conversation->users()->attach($sender->id);

        Queue::fake([SendWebPushNotification::class]);

        $this->actingAs($sender)
            ->post(route('rooms.invitations.store', ['conversation' => $conversation->slug]), [
                'user_ids' => [$recipient->id],
            ])
            ->assertRedirect();

        Queue::assertPushed(
            SendWebPushNotification::class,
            fn (SendWebPushNotification $job): bool => $job->recipientId === $recipient->id
                && $job->payload['type'] === 'room_invitation'
                && $job->payload['body'] === 'Tom invited you to a room'
                && ! str_contains($job->payload['body'], 'Launch room')
                && $job->payload['conversation_id'] === $conversation->id
        );
    }

    public function test_secret_invitation_and_secret_message_push_payloads_are_generic(): void
    {
        $sender = User::factory()->create(['name' => 'Tom']);
        $recipient = User::factory()->create(['name' => 'Alice']);

        Queue::fake([SendWebPushNotification::class]);

        $this->actingAs($sender)
            ->post(route('secret-chats.store', $recipient))
            ->assertRedirect();

        $conversation = Conversation::query()
            ->where('type', ConversationType::Secret)
            ->firstOrFail();

        Queue::assertPushed(
            SendWebPushNotification::class,
            fn (SendWebPushNotification $job): bool => $job->recipientId === $recipient->id
                && $job->payload['type'] === 'secret_chat_invitation'
                && $job->payload['body'] === 'Tom invited you to a secret chat'
                && $job->payload['conversation_id'] === $conversation->id
        );

        $conversation->users()->attach($recipient->id);
        Invitation::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $recipient->id)
            ->update([
                'status' => InvitationStatus::Accepted,
                'responded_at' => now(),
            ]);

        Event::fake([SecretChatMessageSent::class]);

        $this->actingAs($sender)
            ->postJson(route('secret-chats.messages.store', ['conversation' => $conversation->slug]), [
                'ciphertext' => 'encrypted-payload',
                'iv' => 'initial-vector',
                'sender_fingerprint' => str_repeat('f', 64),
            ])
            ->assertCreated();

        Queue::assertPushed(
            SendWebPushNotification::class,
            fn (SendWebPushNotification $job): bool => $job->recipientId === $recipient->id
                && $job->payload['type'] === 'secret_message'
                && $job->payload['body'] === 'Open Padik to read it'
                && ! str_contains(json_encode($job->payload, JSON_THROW_ON_ERROR), 'encrypted-payload')
                && ! str_contains(json_encode($job->payload, JSON_THROW_ON_ERROR), 'initial-vector')
        );
    }

    private function directConversation(User $sender, User $recipient, string $slug): Conversation
    {
        $conversation = Conversation::factory()->create([
            'type' => ConversationType::Direct,
            'title' => null,
            'slug' => $slug,
            'created_by_id' => $sender->id,
        ]);
        $conversation->users()->attach([$sender->id, $recipient->id]);

        return $conversation;
    }
}
