<?php

namespace Tests\Feature;

use App\Enums\ConversationType;
use App\Events\SecretChatDeliveryCreated;
use App\Events\SecretChatMessageSent;
use App\Jobs\SendWebPushNotification;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SecretMessageDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SecretChatEncryptedMailboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_secret_message_creates_pending_encrypted_delivery_without_plaintext_message(): void
    {
        $sender = User::factory()->create(['name' => 'Tom']);
        $recipient = User::factory()->create(['name' => 'Alice']);
        $conversation = $this->secretConversation($sender, $recipient);

        Event::fake([SecretChatDeliveryCreated::class, SecretChatMessageSent::class]);
        Queue::fake([SendWebPushNotification::class]);

        $this->actingAs($sender)
            ->postJson(route('secret-chats.messages.store', ['conversation' => $conversation->slug]), [
                'ciphertext' => 'ciphertext-only-payload',
                'iv' => 'initial-vector',
                'sender_fingerprint' => str_repeat('f', 64),
                'body' => 'plaintext must be ignored',
            ])
            ->assertCreated()
            ->assertJsonMissingPath('message.body')
            ->assertJsonPath('message.ciphertext', 'ciphertext-only-payload');

        $this->assertSame(0, Message::query()->count());

        $delivery = SecretMessageDelivery::query()->firstOrFail();

        $this->assertSame($conversation->id, $delivery->conversation_id);
        $this->assertSame($sender->id, $delivery->sender_id);
        $this->assertSame($recipient->id, $delivery->recipient_id);
        $this->assertSame('ciphertext-only-payload', $delivery->ciphertext);
        $this->assertSame('initial-vector', $delivery->iv);
        $this->assertSame(str_repeat('f', 64), $delivery->sender_fingerprint);
        $this->assertNull($delivery->delivered_at);
        $this->assertStringNotContainsString('plaintext must be ignored', json_encode($delivery->getAttributes(), JSON_THROW_ON_ERROR));

        Event::assertDispatched(
            SecretChatDeliveryCreated::class,
            fn (SecretChatDeliveryCreated $event): bool => $event->conversation->is($conversation)
                && $event->sender->is($sender)
                && $event->recipient->is($recipient)
                && $event->deliveryId === $delivery->id
                && $event->actionUrl === route('secret-chats.show', ['conversation' => $conversation->slug])
        );

        Event::assertDispatched(
            SecretChatMessageSent::class,
            fn (SecretChatMessageSent $event): bool => $event->conversation->is($conversation)
                && $event->sender->is($sender)
                && $event->messageId === $delivery->id
                && $event->ciphertext === 'ciphertext-only-payload'
        );

        Queue::assertPushed(
            SendWebPushNotification::class,
            fn (SendWebPushNotification $job): bool => $job->recipientId === $recipient->id
                && $job->payload['type'] === 'secret_message'
                && $job->payload['title'] === 'New secret message'
                && $job->payload['body'] === 'Open Padik to read it'
                && ! str_contains(json_encode($job->payload, JSON_THROW_ON_ERROR), 'ciphertext-only-payload')
                && ! str_contains(json_encode($job->payload, JSON_THROW_ON_ERROR), 'initial-vector')
        );
    }

    public function test_recipient_fetches_only_their_pending_secret_deliveries(): void
    {
        $sender = User::factory()->create(['name' => 'Tom']);
        $recipient = User::factory()->create(['name' => 'Alice']);
        $conversation = $this->secretConversation($sender, $recipient);

        $delivery = SecretMessageDelivery::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'ciphertext' => 'pending-ciphertext',
            'iv' => 'pending-iv',
            'sender_fingerprint' => str_repeat('a', 64),
        ]);
        SecretMessageDelivery::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'delivered_at' => now(),
        ]);

        $this->actingAs($recipient)
            ->getJson(route('secret-chats.deliveries.index', ['conversation' => $conversation->slug]))
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.id', $delivery->id)
            ->assertJsonPath('messages.0.sender_id', $sender->id)
            ->assertJsonPath('messages.0.author', 'Tom')
            ->assertJsonPath('messages.0.ciphertext', 'pending-ciphertext')
            ->assertJsonPath('messages.0.iv', 'pending-iv')
            ->assertJsonMissingPath('messages.0.body');

        $this->actingAs($sender)
            ->getJson(route('secret-chats.deliveries.index', ['conversation' => $conversation->slug]))
            ->assertOk()
            ->assertJsonCount(0, 'messages');
    }

    public function test_acknowledgement_prevents_repeat_delivery_and_is_recipient_only(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $conversation = $this->secretConversation($sender, $recipient);

        $delivery = SecretMessageDelivery::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
        ]);

        $this->actingAs($sender)
            ->postJson(route('secret-chats.deliveries.ack', [
                'conversation' => $conversation->slug,
                'delivery' => $delivery->id,
            ]))
            ->assertForbidden();

        $this->actingAs($recipient)
            ->postJson(route('secret-chats.deliveries.ack', [
                'conversation' => $conversation->slug,
                'delivery' => $delivery->id,
            ]))
            ->assertOk()
            ->assertJsonPath('acknowledged', true);

        $this->assertModelMissing($delivery);

        $this->actingAs($recipient)
            ->getJson(route('secret-chats.deliveries.index', ['conversation' => $conversation->slug]))
            ->assertOk()
            ->assertJsonCount(0, 'messages');
    }

    public function test_non_participant_cannot_fetch_or_ack_secret_deliveries(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $intruder = User::factory()->create();
        $conversation = $this->secretConversation($sender, $recipient);

        $delivery = SecretMessageDelivery::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
        ]);

        $this->actingAs($intruder)
            ->getJson(route('secret-chats.deliveries.index', ['conversation' => $conversation->slug]))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->postJson(route('secret-chats.deliveries.ack', [
                'conversation' => $conversation->slug,
                'delivery' => $delivery->id,
            ]))
            ->assertForbidden();
    }

    private function secretConversation(User $sender, User $recipient): Conversation
    {
        $conversation = Conversation::factory()->secret()->create([
            'type' => ConversationType::Secret,
            'slug' => 'secret-thread',
            'created_by_id' => $sender->id,
        ]);
        $conversation->users()->attach([$sender->id, $recipient->id]);

        return $conversation;
    }
}
