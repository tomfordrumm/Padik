<?php

namespace App\Models;

use App\Enums\ConversationParticipantRole;
use Database\Factories\ConversationParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['conversation_id', 'user_id', 'role', 'unread_count', 'last_read_at'])]
class ConversationParticipant extends Pivot
{
    /** @use HasFactory<ConversationParticipantFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $table = 'conversation_participants';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ConversationParticipantRole::class,
            'unread_count' => 'integer',
            'last_read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
