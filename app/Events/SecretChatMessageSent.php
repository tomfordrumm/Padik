<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SecretChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public User $sender,
        public string $ciphertext,
        public string $iv,
        public string $senderFingerprint,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('rooms.'.$this->conversation->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SecretChatMessageSent';
    }

    /**
     * @return array{message: array{id: string, sender_id: int, author: string, ciphertext: string, iv: string, sender_fingerprint: string, time: string}, conversation: array{id: int, slug: string, type: string}}
     */
    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => (string) str()->uuid(),
                'sender_id' => $this->sender->id,
                'author' => $this->sender->name,
                'ciphertext' => $this->ciphertext,
                'iv' => $this->iv,
                'sender_fingerprint' => $this->senderFingerprint,
                'time' => now()->format('H:i'),
            ],
            'conversation' => [
                'id' => $this->conversation->id,
                'slug' => $this->conversation->slug,
                'type' => $this->conversation->type->value,
            ],
        ];
    }
}
