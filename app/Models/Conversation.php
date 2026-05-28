<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'title', 'slug', 'status', 'created_by_id'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'status' => ConversationStatus::class,
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->using(ConversationParticipant::class)
            ->withPivot(['id', 'role', 'unread_count', 'last_read_at', 'secret_public_key', 'secret_key_fingerprint'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function firstUnreadMessageIdFor(User $user): ?int
    {
        $participant = $this->participants()
            ->where('user_id', $user->id)
            ->first();

        if (! $participant || $participant->unread_count === 0) {
            return null;
        }

        $messages = $this->messages()
            ->where('user_id', '!=', $user->id)
            ->oldest();

        if ($participant->last_read_at) {
            $messages->where('created_at', '>', $participant->last_read_at);

            $messageId = $messages->value('id');

            return $messageId ? (int) $messageId : null;
        }

        $message = $this->messages()
            ->where('user_id', '!=', $user->id)
            ->latest()
            ->limit($participant->unread_count)
            ->get(['id'])
            ->sortBy('id')
            ->first();

        return $message ? (int) $message->id : null;
    }

    public function markReadFor(User $user): void
    {
        $this->participants()
            ->where('user_id', $user->id)
            ->update([
                'unread_count' => 0,
                'last_read_at' => now(),
            ]);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }
}
