<?php

namespace App\Http\Requests;

use App\Enums\ConversationType;
use App\Models\Conversation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSecretChatKeyRequest extends FormRequest
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
            'public_key' => ['required', 'array'],
            'public_key.kty' => ['required', 'string', 'in:EC'],
            'public_key.crv' => ['required', 'string', 'in:P-256'],
            'public_key.x' => ['required', 'string', 'max:128'],
            'public_key.y' => ['required', 'string', 'max:128'],
            'fingerprint' => ['required', 'string', 'size:64'],
        ];
    }
}
