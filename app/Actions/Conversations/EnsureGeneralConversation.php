<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationParticipantRole;
use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;

class EnsureGeneralConversation
{
    public function conversation(): Conversation
    {
        return Conversation::query()->firstOrCreate(
            ['type' => ConversationType::General],
            [
                'title' => 'General',
                'slug' => 'general',
                'status' => ConversationStatus::Active,
                'created_by_id' => null,
            ],
        );
    }

    public function addUser(User $user): ConversationParticipant
    {
        return ConversationParticipant::query()->firstOrCreate(
            [
                'conversation_id' => $this->conversation()->id,
                'user_id' => $user->id,
            ],
            [
                'role' => ConversationParticipantRole::Member,
                'unread_count' => 0,
            ],
        );
    }

    public function addAllUsers(): void
    {
        User::query()
            ->select('id')
            ->cursor()
            ->each(fn (User $user) => $this->addUser($user));
    }
}
