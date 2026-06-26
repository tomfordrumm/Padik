<?php

namespace App\Http\Requests;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\SecretMessageDelivery;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AcknowledgeSecretChatDeliveryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Conversation|null $conversation */
        $conversation = $this->route('conversation');

        /** @var SecretMessageDelivery|null $delivery */
        $delivery = $this->route('delivery');

        return $conversation !== null
            && $delivery !== null
            && $conversation->type === ConversationType::Secret
            && (int) $delivery->conversation_id === (int) $conversation->id
            && (int) $delivery->recipient_id === (int) $this->user()?->id
            && $this->user()?->conversations()->whereKey($conversation->id)->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            //
        ];
    }
}
