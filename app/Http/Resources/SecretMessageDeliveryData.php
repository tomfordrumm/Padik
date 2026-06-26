<?php

namespace App\Http\Resources;

use App\Models\SecretMessageDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SecretMessageDelivery
 */
class SecretMessageDeliveryData extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{id: string, sender_id: int, author: string, ciphertext: string, iv: string, sender_fingerprint: string, time: string, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('sender:id,name');

        return [
            'id' => $this->id,
            'sender_id' => $this->sender_id,
            'author' => $this->sender->name,
            'ciphertext' => $this->ciphertext,
            'iv' => $this->iv,
            'sender_fingerprint' => $this->sender_fingerprint,
            'time' => $this->created_at?->format('H:i') ?? '',
            'created_at' => $this->created_at?->toJSON(),
        ];
    }
}
