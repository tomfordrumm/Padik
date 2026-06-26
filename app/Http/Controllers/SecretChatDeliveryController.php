<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcknowledgeSecretChatDeliveryRequest;
use App\Http\Requests\IndexSecretChatDeliveryRequest;
use App\Http\Resources\SecretMessageDeliveryData;
use App\Models\Conversation;
use App\Models\SecretMessageDelivery;
use Illuminate\Http\JsonResponse;

class SecretChatDeliveryController extends Controller
{
    public function index(IndexSecretChatDeliveryRequest $request, Conversation $conversation): JsonResponse
    {
        $deliveries = $conversation
            ->secretMessageDeliveries()
            ->with('sender:id,name')
            ->pending()
            ->where('recipient_id', $request->user()->id)
            ->oldest()
            ->get();

        return response()->json([
            'messages' => SecretMessageDeliveryData::collection($deliveries)->resolve($request),
        ]);
    }

    public function acknowledge(
        AcknowledgeSecretChatDeliveryRequest $request,
        Conversation $conversation,
        SecretMessageDelivery $delivery,
    ): JsonResponse {
        $delivery->delete();

        return response()->json([
            'acknowledged' => true,
        ]);
    }
}
