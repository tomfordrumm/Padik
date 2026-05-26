<?php

namespace App\Http\Controllers;

use App\Enums\ConversationType;
use App\Http\Resources\MessageData;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
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

        return Inertia::render('Dashboard', [
            'currentRoom' => [
                'id' => $conversation->id,
                'title' => $this->titleFor($conversation, $request->user()),
                'slug' => $conversation->slug,
                'type' => $conversation->type->value,
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
}
