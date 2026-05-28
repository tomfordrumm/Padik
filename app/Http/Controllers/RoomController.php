<?php

namespace App\Http\Controllers;

use App\Enums\ConversationParticipantRole;
use App\Enums\ConversationType;
use App\Http\Requests\StoreGroupRoomRequest;
use App\Http\Resources\MessageData;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    public function store(StoreGroupRoomRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $title = trim($validated['title']);

        $conversation = Conversation::query()->create([
            'type' => ConversationType::Group,
            'title' => $title,
            'slug' => $this->uniqueSlug($title),
            'created_by_id' => $request->user()->id,
        ]);

        $conversation->users()->attach($request->user()->id, [
            'role' => ConversationParticipantRole::Owner->value,
            'last_read_at' => now(),
        ]);

        return to_route('rooms.show', ['conversation' => $conversation->slug]);
    }

    public function __invoke(Request $request, Conversation $conversation): Response
    {
        abort_unless(
            $request->user()?->conversations()->whereKey($conversation->id)->exists(),
            404,
        );

        $conversation->participants()
            ->where('user_id', $request->user()->id)
            ->update([
                'unread_count' => 0,
                'last_read_at' => now(),
            ]);

        return Inertia::render('Conversation', [
            'currentRoom' => [
                'id' => $conversation->id,
                'title' => $this->titleFor($conversation, $request->user()),
                'slug' => $conversation->slug,
                'type' => $conversation->type->value,
                'created_by_id' => $conversation->created_by_id,
                'can_manage' => $conversation->type === ConversationType::Group
                    && $conversation->created_by_id === $request->user()->id,
                'direct_user_id' => $this->directUserIdFor($conversation, $request->user()),
            ],
            'messages' => $conversation->messages()
                ->with('user:id,name,email')
                ->oldest()
                ->get()
                ->map(fn ($message): array => MessageData::fromMessage($message, $request->user())),
        ]);
    }

    private function titleFor(Conversation $conversation, User $user): string
    {
        if ($conversation->type !== ConversationType::Direct) {
            return $conversation->title ?? 'Untitled room';
        }

        return $conversation->users()
            ->whereKeyNot($user->id)
            ->value('name') ?? 'Direct message';
    }

    private function directUserIdFor(Conversation $conversation, User $user): ?int
    {
        if ($conversation->type !== ConversationType::Direct) {
            return null;
        }

        return $conversation->users()
            ->whereKeyNot($user->id)
            ->value('users.id');
    }

    private function uniqueSlug(string $title): string
    {
        $slug = Str::slug($title);
        $baseSlug = $slug !== '' ? $slug : 'room';
        $candidate = $baseSlug;
        $suffix = 2;

        while (Conversation::query()->where('slug', $candidate)->exists()) {
            $candidate = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}
