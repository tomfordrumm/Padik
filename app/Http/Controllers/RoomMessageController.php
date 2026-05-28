<?php

namespace App\Http\Controllers;

use App\Enums\ConversationType;
use App\Events\RoomMessageSent;
use App\Http\Requests\StoreRoomMessageRequest;
use App\Http\Resources\MessageData;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\DirectMessageReceived;
use App\Notifications\MentionReceived;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RoomMessageController extends Controller
{
    public function store(StoreRoomMessageRequest $request, Conversation $conversation): JsonResponse|RedirectResponse
    {
        $body = $request->validated('body');

        $message = $conversation->messages()->create([
            'user_id' => $request->user()->id,
            'body' => $body,
        ]);

        $conversation->participants()
            ->where('user_id', '!=', $request->user()->id)
            ->increment('unread_count');

        broadcast(new RoomMessageSent($message))->toOthers();

        if ($conversation->type === ConversationType::Direct) {
            $conversation->users()
                ->whereKeyNot($request->user()->id)
                ->get()
                ->each(fn (User $recipient) => $recipient->notify(new DirectMessageReceived($message)));
        }

        $this->mentionedRecipients($conversation, $request->user(), $body)
            ->each(fn (User $recipient) => $recipient->notify(new MentionReceived($message)));

        if ($request->expectsJson()) {
            return response()->json([
                'message' => MessageData::fromMessage($message, $request->user()),
                'conversation' => [
                    'id' => $conversation->id,
                    'slug' => $conversation->slug,
                    'type' => $conversation->type->value,
                    'direct_user_id' => $conversation->type === ConversationType::Direct
                        ? $conversation->users()
                            ->whereKeyNot($request->user()->id)
                            ->value('users.id')
                        : null,
                ],
            ], 201);
        }

        return to_route('rooms.show', ['conversation' => $conversation->slug]);
    }

    /**
     * @return Collection<int, User>
     */
    private function mentionedRecipients(Conversation $conversation, User $sender, string $body): Collection
    {
        $mentionableUsers = $conversation->users()
            ->select(['users.id', 'users.name'])
            ->whereKeyNot($sender->id)
            ->get();

        return $mentionableUsers
            ->filter(fn (User $user): bool => $this->bodyMentionsUser($body, $user))
            ->values();
    }

    private function bodyMentionsUser(string $body, User $user): bool
    {
        $name = preg_quote($user->name, '/');

        return Str::of($body)->test("/(?<![\\p{L}\\p{N}_])@{$name}(?![\\p{L}\\p{N}_])/u");
    }
}
