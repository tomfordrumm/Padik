<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationParticipantRole;
use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EnsureSecretConversation
{
    public function between(User $creator, User $recipient): Conversation
    {
        abort_if($creator->is($recipient), 404);

        return DB::transaction(function () use ($creator): Conversation {
            $conversation = Conversation::query()->create([
                'type' => ConversationType::Secret,
                'title' => null,
                'slug' => $this->slugFor(),
                'status' => ConversationStatus::Active,
                'created_by_id' => $creator->id,
            ]);

            $conversation->users()->attach([
                $creator->id => ['role' => ConversationParticipantRole::Member],
            ]);

            return $conversation;
        });
    }

    private function slugFor(): string
    {
        do {
            $slug = 'secret-'.strtolower(str()->random(24));
        } while (Conversation::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
