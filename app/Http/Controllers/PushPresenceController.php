<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePushPresenceRequest;
use App\Services\Push\ActivePushConversationService;
use Illuminate\Http\JsonResponse;

class PushPresenceController extends Controller
{
    public function store(
        StorePushPresenceRequest $request,
        ActivePushConversationService $activeConversations,
    ): JsonResponse {
        $activeConversations->markViewing(
            $request->user(),
            $request->conversation(),
            $request->session()->getId(),
        );

        return response()->json(['active' => true]);
    }

    public function destroy(
        StorePushPresenceRequest $request,
        ActivePushConversationService $activeConversations,
    ): JsonResponse {
        $activeConversations->clearViewing(
            $request->user(),
            $request->conversation(),
            $request->session()->getId(),
        );

        return response()->json(['active' => false]);
    }
}
