<?php

namespace App\Http\Resources;

use Illuminate\Notifications\DatabaseNotification;

class NotificationData
{
    /**
     * @return array{id: string, type: string, title: string, body: string|null, sender_id: int|null, sender_name: string|null, action_url: string|null, read_at: string|null, created_at: string, created_at_human: string}
     */
    public static function fromNotification(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->data['title'] ?? 'Notification',
            'body' => $notification->data['body'] ?? null,
            'invitation_id' => $notification->data['invitation_id'] ?? null,
            'conversation_id' => $notification->data['conversation_id'] ?? null,
            'room_title' => $notification->data['room_title'] ?? null,
            'sender_id' => $notification->data['sender_id'] ?? null,
            'sender_name' => $notification->data['sender_name'] ?? null,
            'action_url' => $notification->data['action_url'] ?? null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at->toIso8601String(),
            'created_at_human' => $notification->created_at->diffForHumans(),
        ];
    }
}
