<?php

namespace App\Http\Resources;

use App\Models\Message;
use App\Models\User;

class MessageData
{
    /**
     * @return array{id: int, sender_id: int, author: string, body: string|null, time: string, own: bool}
     */
    public static function fromMessage(Message $message, ?User $viewer = null): array
    {
        $message->loadMissing('user');

        return [
            'id' => $message->id,
            'sender_id' => $message->user_id,
            'author' => $message->user->name,
            'body' => $message->body,
            'time' => $message->created_at->format('H:i'),
            'own' => $viewer?->is($message->user) ?? false,
        ];
    }
}
