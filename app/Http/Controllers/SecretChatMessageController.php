<?php

namespace App\Http\Controllers;

use App\Events\SecretChatDeliveryCreated;
use App\Events\SecretChatMessageSent;
use App\Http\Requests\StoreSecretChatMessageRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Push\MessengerPushNotificationService;
use Illuminate\Http\JsonResponse;

class SecretChatMessageController extends Controller
{
    public function store(
        StoreSecretChatMessageRequest $request,
        Conversation $conversation,
        MessengerPushNotificationService $pushNotifications,
    ): JsonResponse {
        $validated = $request->validated();
        $sender = $request->user();
        $sentAt = now();
        $messageId = (string) str()->uuid();

        $conversation
            ->users()
            ->whereKeyNot($sender->id)
            ->get()
            ->each(function (User $recipient) use ($conversation, $sender, $validated, $messageId, $sentAt, $pushNotifications): void {
                $delivery = $conversation->secretMessageDeliveries()->create([
                    'id' => $messageId,
                    'sender_id' => $sender->id,
                    'recipient_id' => $recipient->id,
                    'ciphertext' => $validated['ciphertext'],
                    'iv' => $validated['iv'],
                    'sender_fingerprint' => $validated['sender_fingerprint'],
                    'created_at' => $sentAt,
                    'updated_at' => $sentAt,
                ]);

                SecretChatDeliveryCreated::dispatch(
                    conversation: $conversation,
                    sender: $sender,
                    recipient: $recipient,
                    deliveryId: $delivery->id,
                    actionUrl: route('secret-chats.show', ['conversation' => $conversation->slug]),
                );

                $pushNotifications->dispatchSecretMessage($conversation, $sender, $recipient);
            });

        broadcast(new SecretChatMessageSent(
            conversation: $conversation,
            sender: $sender,
            ciphertext: $validated['ciphertext'],
            iv: $validated['iv'],
            senderFingerprint: $validated['sender_fingerprint'],
            messageId: $messageId,
            sentAt: $sentAt,
        ))->toOthers();

        return response()->json([
            'message' => [
                'id' => $messageId,
                'sender_id' => $sender->id,
                'author' => $sender->name,
                'ciphertext' => $validated['ciphertext'],
                'iv' => $validated['iv'],
                'sender_fingerprint' => $validated['sender_fingerprint'],
                'time' => $sentAt->format('H:i'),
                'created_at' => $sentAt->toJSON(),
            ],
            'conversation' => [
                'id' => $conversation->id,
                'slug' => $conversation->slug,
                'type' => $conversation->type->value,
            ],
        ], 201);
    }
}
