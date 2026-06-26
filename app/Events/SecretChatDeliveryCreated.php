<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SecretChatDeliveryCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public User $sender,
        public User $recipient,
        public string $deliveryId,
        public string $actionUrl,
    ) {}
}
