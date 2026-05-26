<?php

namespace App\Http\Requests;

use App\Enums\ConversationType;
use App\Models\Conversation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSecretChatMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Conversation|null $conversation */
        $conversation = $this->route('conversation');

        return $conversation !== null
            && $conversation->type === ConversationType::Secret
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
            'ciphertext' => ['required', 'string', 'max:12000'],
            'iv' => ['required', 'string', 'max:64'],
            'sender_fingerprint' => ['required', 'string', 'size:64'],
        ];
    }
}
