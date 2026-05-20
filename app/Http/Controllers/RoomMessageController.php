<?php

namespace App\Http\Controllers;

use App\Enums\ConversationType;
use App\Events\RoomMessageSent;
use App\Http\Requests\StoreRoomMessageRequest;
use App\Http\Resources\MessageData;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\DirectMessageReceived;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class RoomMessageController extends Controller
{
    public function store(StoreRoomMessageRequest $request, Conversation $conversation): JsonResponse|RedirectResponse
    {
        $message = $conversation->messages()->create([
            'user_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        broadcast(new RoomMessageSent($message))->toOthers();

        if ($conversation->type === ConversationType::Direct) {
            $conversation->users()
                ->whereKeyNot($request->user()->id)
                ->get()
                ->each(fn (User $recipient) => $recipient->notify(new DirectMessageReceived($message)));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => MessageData::fromMessage($message, $request->user()),
            ], 201);
        }

        return to_route('rooms.show', ['conversation' => $conversation->slug]);
    }
}
