<?php

namespace App\Http\Controllers;

use App\Events\SecretChatKeyUpdated;
use App\Http\Requests\StoreSecretChatKeyRequest;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class SecretChatKeyController extends Controller
{
    public function store(StoreSecretChatKeyRequest $request, Conversation $conversation): JsonResponse|RedirectResponse
    {
        $publicKey = $request->validated('public_key');
        $fingerprint = $request->validated('fingerprint');

        $conversation->participants()
            ->where('user_id', $request->user()->id)
            ->update([
                'secret_public_key' => $publicKey,
                'secret_key_fingerprint' => $fingerprint,
            ]);

        broadcast(new SecretChatKeyUpdated(
            conversation: $conversation,
            user: $request->user(),
            publicKey: $publicKey,
            fingerprint: $fingerprint,
        ))->toOthers();

        if ($request->expectsJson()) {
            return response()->json([
                'public_key' => $publicKey,
                'fingerprint' => $fingerprint,
            ]);
        }

        return back();
    }
}
