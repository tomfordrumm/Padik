<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoomMessageRequest;
use App\Models\Conversation;
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

        if ($request->expectsJson()) {
            return response()->json([
                'message' => [
                    'id' => $message->id,
                    'sender_id' => $message->user_id,
                    'author' => $request->user()->name,
                    'body' => $message->body,
                    'time' => $message->created_at->format('H:i'),
                    'own' => true,
                ],
            ], 201);
        }

        return to_route('rooms.show', ['conversation' => $conversation->slug]);
    }
}
