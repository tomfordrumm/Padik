<?php

namespace App\Events;

use App\Enums\ConversationType;
use App\Http\Resources\MessageData;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoomMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('rooms.'.$this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'RoomMessageSent';
    }

    /**
     * @return array{message: array{id: int, sender_id: int, author: string, body: string|null, time: string, own: bool}, conversation: array{id: int, slug: string, type: string, direct_user_id: int|null}}
     */
    public function broadcastWith(): array
    {
        $this->message->loadMissing('conversation');

        return [
            'message' => MessageData::fromMessage($this->message),
            'conversation' => [
                'id' => $this->message->conversation->id,
                'slug' => $this->message->conversation->slug,
                'type' => $this->message->conversation->type->value,
                'direct_user_id' => $this->message->conversation->type === ConversationType::Direct
                    ? $this->message->user_id
                    : null,
            ],
        ];
    }
}
