<?php

namespace App\Http\Controllers;

use App\Events\SecretChatMessageSent;
use App\Http\Requests\StoreSecretChatMessageRequest;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;

class SecretChatMessageController extends Controller
{
    public function store(StoreSecretChatMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validated();

        broadcast(new SecretChatMessageSent(
            conversation: $conversation,
            sender: $request->user(),
            ciphertext: $validated['ciphertext'],
            iv: $validated['iv'],
            senderFingerprint: $validated['sender_fingerprint'],
        ))->toOthers();

        return response()->json([
            'message' => [
                'id' => (string) str()->uuid(),
                'sender_id' => $request->user()->id,
                'author' => $request->user()->name,
                'ciphertext' => $validated['ciphertext'],
                'iv' => $validated['iv'],
                'sender_fingerprint' => $validated['sender_fingerprint'],
                'time' => now()->format('H:i'),
            ],
            'conversation' => [
                'id' => $conversation->id,
                'slug' => $conversation->slug,
                'type' => $conversation->type->value,
            ],
        ], 201);
    }
}
