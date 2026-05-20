<?php

namespace App\Http\Resources;

use Illuminate\Notifications\DatabaseNotification;

class NotificationData
{
    /**
     * @return array{id: string, type: string, title: string, body: string|null, sender_name: string|null, read_at: string|null, created_at: string, created_at_human: string}
     */
    public static function fromNotification(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => 'New direct message',
            'body' => $notification->data['body'] ?? null,
            'sender_name' => $notification->data['sender_name'] ?? null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at->toIso8601String(),
            'created_at_human' => $notification->created_at->diffForHumans(),
        ];
    }
}
