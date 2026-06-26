<?php

namespace App\Services\Push;

use Carbon\CarbonInterface;

final readonly class PushNotificationPayload
{
    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public string $actionUrl,
        public ?string $notificationId,
        public ?int $conversationId,
        public ?int $senderId,
        public string $tag,
        public CarbonInterface $timestamp,
    ) {}

    /**
     * @return array{type: string, title: string, body: string, action_url: string, notification_id: string|null, conversation_id: int|null, sender_id: int|null, tag: string, timestamp: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->actionUrl,
            'notification_id' => $this->notificationId,
            'conversation_id' => $this->conversationId,
            'sender_id' => $this->senderId,
            'tag' => $this->tag,
            'timestamp' => $this->timestamp->toIso8601String(),
        ];
    }
}
