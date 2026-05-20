<?php

namespace Tests\Feature;

use App\Actions\Conversations\EnsureGeneralConversation;
use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->actingAs($user)
            ->postJson('/r/general/messages', [
                'body' => 'Posted without a page visit.',
            ])
            ->assertCreated()
            ->assertJsonPath('message.sender_id', $user->id)
            ->assertJsonPath('message.author', $user->name)
            ->assertJsonPath('message.body', 'Posted without a page visit.')
            ->assertJsonPath('message.own', true);

        $this->assertDatabaseHas('messages', [
            'user_id' => $user->id,
            'body' => 'Posted without a page visit.',
        ]);
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
