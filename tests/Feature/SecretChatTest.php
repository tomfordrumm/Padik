<?php

namespace Tests\Feature;

use App\Enums\ConversationType;
use App\Enums\InvitationStatus;
use App\Events\SecretChatMessageSent;
use App\Models\Conversation;
use App\Models\Invitation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\SecretChatInvitationReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SecretChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_a_secret_chat(): void
    {
        $user = User::factory()->create();
        $recipient = User::factory()->create(['name' => 'Alice']);

        $this->actingAs($user)
            ->post(route('secret-chats.store', $recipient))
            ->assertRedirect();

        $conversation = Conversation::query()
            ->where('type', ConversationType::Secret)
            ->firstOrFail();

        $this->assertStringStartsWith('secret-', $conversation->slug);
        $this->assertTrue($conversation->users()->whereKey($user->id)->exists());
        $this->assertFalse($conversation->users()->whereKey($recipient->id)->exists());

        $invitation = Invitation::query()->firstOrFail();

        $this->assertSame($conversation->id, $invitation->conversation_id);
        $this->assertSame($user->id, $invitation->sender_id);
        $this->assertSame($recipient->id, $invitation->user_id);
        $this->assertSame(InvitationStatus::Pending, $invitation->status);

        $notification = $recipient->notifications()->firstOrFail();

        $this->assertSame(SecretChatInvitationReceived::class, $notification->type);
        $this->assertSame($invitation->id, $notification->data['invitation_id']);
        $this->assertSame(route('secret-chats.show', ['conversation' => $conversation->slug]), $notification->data['action_url']);
    }

    public function test_secret_chat_invitation_acceptance_adds_recipient_and_opens_secret_chat(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $conversation = Conversation::factory()->secret()->create([
            'slug' => 'secret-thread',
            'created_by_id' => $sender->id,
        ]);
        $conversation->users()->attach($sender->id);
        $invitation = Invitation::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'user_id' => $recipient->id,
            'status' => InvitationStatus::Pending,
        ]);

        $recipient->notify(new SecretChatInvitationReceived($invitation));
        $notification = $recipient->notifications()->firstOrFail();

        $this->actingAs($recipient)
            ->post(route('notifications.invitations.accept', $notification))
            ->assertRedirect(route('secret-chats.show', ['conversation' => $conversation->slug]));

        $this->assertTrue($conversation->users()->whereKey($recipient->id)->exists());
        $this->assertDatabaseHas('invitations', [
            'id' => $invitation->id,
            'status' => InvitationStatus::Accepted->value,
        ]);
    }

    public function test_secret_chat_page_shares_key_exchange_metadata_without_messages(): void
    {
        $user = User::factory()->create();
        $recipient = User::factory()->create(['name' => 'Alice']);
        $conversation = Conversation::factory()->secret()->create([
            'slug' => 'secret-thread',
            'created_by_id' => $user->id,
        ]);
        $conversation->users()->attach([$user->id, $recipient->id]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $recipient->id,
            'body' => 'Server stored messages must not be loaded here.',
        ]);

        $this->actingAs($user)
            ->get(route('secret-chats.show', ['conversation' => $conversation->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('currentRoom.type', ConversationType::Secret->value)
                ->where('currentRoom.title', 'Alice')
                ->has('messages', 0)
                ->has('secretChat.participants', 2)
            );
    }

    public function test_secret_chat_participant_can_publish_public_key(): void
    {
        $user = User::factory()->create();
        $recipient = User::factory()->create();
        $conversation = Conversation::factory()->secret()->create(['slug' => 'secret-thread']);
        $conversation->users()->attach([$user->id, $recipient->id]);

        $publicKey = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => str_repeat('a', 43),
            'y' => str_repeat('b', 43),
        ];
        $fingerprint = str_repeat('a', 64);

        $this->actingAs($user)
            ->post(route('secret-chats.key.store', ['conversation' => $conversation->slug]), [
                'public_key' => $publicKey,
                'fingerprint' => $fingerprint,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'secret_key_fingerprint' => $fingerprint,
        ]);
    }

    public function test_secret_chat_public_key_endpoint_returns_json_for_http_requests(): void
    {
        $user = User::factory()->create();
        $recipient = User::factory()->create();
        $conversation = Conversation::factory()->secret()->create(['slug' => 'secret-thread']);
        $conversation->users()->attach([$user->id, $recipient->id]);

        $publicKey = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => str_repeat('a', 43),
            'y' => str_repeat('b', 43),
        ];
        $fingerprint = str_repeat('a', 64);

        $this->actingAs($user)
            ->postJson(route('secret-chats.key.store', ['conversation' => $conversation->slug]), [
                'public_key' => $publicKey,
                'fingerprint' => $fingerprint,
            ])
            ->assertOk()
            ->assertJsonPath('fingerprint', $fingerprint)
            ->assertJsonPath('public_key.kty', 'EC');
    }

    public function test_secret_chat_message_broadcasts_ciphertext_without_storing_message(): void
    {
        Event::fake([SecretChatMessageSent::class]);

        $user = User::factory()->create();
        $recipient = User::factory()->create();
        $conversation = Conversation::factory()->secret()->create(['slug' => 'secret-thread']);
        $conversation->users()->attach([$user->id, $recipient->id]);

        $this->actingAs($user)
            ->postJson(route('secret-chats.messages.store', ['conversation' => $conversation->slug]), [
                'ciphertext' => 'encrypted-payload',
                'iv' => 'initial-vector',
                'sender_fingerprint' => str_repeat('f', 64),
            ])
            ->assertCreated()
            ->assertJsonPath('message.ciphertext', 'encrypted-payload')
            ->assertJsonMissingPath('message.body');

        $this->assertSame(0, Message::query()->count());

        Event::assertDispatched(
            SecretChatMessageSent::class,
            fn (SecretChatMessageSent $event): bool => $event->conversation->is($conversation)
                && $event->sender->is($user)
                && $event->ciphertext === 'encrypted-payload'
        );
    }
}
