<?php

namespace App\Services\Push;

use App\Contracts\PushNotificationDispatcher;
use App\Models\Conversation;
use App\Models\Invitation;
use App\Models\Message;
use App\Models\User;

class MessengerPushNotificationService
{
    public function __construct(
        private ActivePushConversationService $activeConversations,
        private PushNotificationDispatcher $dispatcher,
    ) {}

    public function dispatchDirectMessage(Message $message, User $recipient): void
    {
        $message->loadMissing(['conversation', 'user']);

        $this->dispatchIfAllowed($recipient, $message->conversation, $message->user, new PushNotificationPayload(
            type: 'direct_message',
            title: 'New direct message',
            body: "{$message->user->name} sent you a message",
            actionUrl: route('direct-messages.show', $message->user),
            notificationId: null,
            conversationId: $message->conversation_id,
            senderId: $message->user_id,
            tag: "conversation-{$message->conversation_id}",
            timestamp: $message->created_at,
        ));
    }

    public function dispatchMention(Message $message, User $recipient): void
    {
        $message->loadMissing(['conversation', 'user']);

        $roomTitle = $message->conversation->title ?? 'Direct message';

        $this->dispatchIfAllowed($recipient, $message->conversation, $message->user, new PushNotificationPayload(
            type: 'mention',
            title: 'You were mentioned',
            body: "{$message->user->name} mentioned you in {$roomTitle}",
            actionUrl: route('rooms.show', $message->conversation).'#message-'.$message->id,
            notificationId: null,
            conversationId: $message->conversation_id,
            senderId: $message->user_id,
            tag: "conversation-{$message->conversation_id}",
            timestamp: $message->created_at,
        ));
    }

    public function dispatchRoomInvitation(Invitation $invitation, User $recipient): void
    {
        $invitation->loadMissing(['conversation', 'sender']);

        $this->dispatchIfAllowed($recipient, $invitation->conversation, $invitation->sender, new PushNotificationPayload(
            type: 'room_invitation',
            title: 'Room invitation',
            body: "{$invitation->sender->name} invited you to a room",
            actionUrl: route('rooms.show', $invitation->conversation),
            notificationId: null,
            conversationId: $invitation->conversation_id,
            senderId: $invitation->sender_id,
            tag: "conversation-{$invitation->conversation_id}",
            timestamp: $invitation->created_at,
        ));
    }

    public function dispatchSecretChatInvitation(Invitation $invitation, User $recipient): void
    {
        $invitation->loadMissing(['conversation', 'sender']);

        $this->dispatchIfAllowed($recipient, $invitation->conversation, $invitation->sender, new PushNotificationPayload(
            type: 'secret_chat_invitation',
            title: 'Secret chat invitation',
            body: "{$invitation->sender->name} invited you to a secret chat",
            actionUrl: route('secret-chats.show', ['conversation' => $invitation->conversation->slug]),
            notificationId: null,
            conversationId: $invitation->conversation_id,
            senderId: $invitation->sender_id,
            tag: "conversation-{$invitation->conversation_id}",
            timestamp: $invitation->created_at,
        ));
    }

    public function dispatchSecretMessage(Conversation $conversation, User $sender, User $recipient): void
    {
        $this->dispatchIfAllowed($recipient, $conversation, $sender, new PushNotificationPayload(
            type: 'secret_message',
            title: 'New secret message',
            body: 'Open Padik to read it',
            actionUrl: route('secret-chats.show', ['conversation' => $conversation->slug]),
            notificationId: null,
            conversationId: $conversation->id,
            senderId: $sender->id,
            tag: "conversation-{$conversation->id}",
            timestamp: now(),
        ));
    }

    private function dispatchIfAllowed(
        User $recipient,
        Conversation $conversation,
        User $sender,
        PushNotificationPayload $payload,
    ): void {
        if ($recipient->is($sender)) {
            return;
        }

        if ($this->activeConversations->isViewing($recipient, $conversation)) {
            return;
        }

        $this->dispatcher->dispatch($recipient, $payload);
    }
}
