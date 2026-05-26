<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSecretChatKeyRequest;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class SecretChatKeyController extends Controller
{
    public function store(StoreSecretChatKeyRequest $request, Conversation $conversation): JsonResponse|RedirectResponse
    {
        $conversation->participants()
            ->where('user_id', $request->user()->id)
            ->update([
                'secret_public_key' => $request->validated('public_key'),
                'secret_key_fingerprint' => $request->validated('fingerprint'),
            ]);

        if ($request->expectsJson()) {
            return response()->json([
                'public_key' => $request->validated('public_key'),
                'fingerprint' => $request->validated('fingerprint'),
            ]);
        }

        return back();
    }
}
