<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SecretChatKeyUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $publicKey
     */
    public function __construct(
        public Conversation $conversation,
        public User $user,
        public array $publicKey,
        public string $fingerprint,
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
        return 'SecretChatKeyUpdated';
    }

    /**
     * @return array{participant: array{id: int, name: string, public_key: array<string, mixed>, fingerprint: string}, conversation: array{id: int, slug: string, type: string}}
     */
    public function broadcastWith(): array
    {
        return [
            'participant' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'public_key' => $this->publicKey,
                'fingerprint' => $this->fingerprint,
            ],
            'conversation' => [
                'id' => $this->conversation->id,
                'slug' => $this->conversation->slug,
                'type' => $this->conversation->type->value,
            ],
        ];
    }
}
