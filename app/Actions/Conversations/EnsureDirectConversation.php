<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationParticipantRole;
use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EnsureDirectConversation
{
    public function between(User $creator, User $recipient): Conversation
    {
        abort_if($creator->is($recipient), 404);

        $existingConversation = Conversation::query()
            ->where('type', ConversationType::Direct)
            ->where('status', ConversationStatus::Active)
            ->whereHas('users', fn ($query) => $query->whereKey($creator->id))
            ->whereHas('users', fn ($query) => $query->whereKey($recipient->id))
            ->first();

        if ($existingConversation !== null) {
            return $existingConversation;
        }

        return DB::transaction(function () use ($creator, $recipient): Conversation {
            $conversation = Conversation::query()->create([
                'type' => ConversationType::Direct,
                'title' => null,
                'slug' => $this->slugFor($creator, $recipient),
                'status' => ConversationStatus::Active,
                'created_by_id' => $creator->id,
            ]);

            $conversation->users()->attach([
                $creator->id => ['role' => ConversationParticipantRole::Member],
                $recipient->id => ['role' => ConversationParticipantRole::Member],
            ]);

            return $conversation;
        });
    }

    private function slugFor(User $creator, User $recipient): string
    {
        $userIds = [$creator->id, $recipient->id];
        sort($userIds);

        return "dm-{$userIds[0]}-{$userIds[1]}";
    }
}
