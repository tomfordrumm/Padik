<?php

namespace App\Services;

use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;

class MessengerNavigation
{
    /**
     * @return Collection<int, array{id: int, title: string, slug: string, type: string, unread_count: int, last_message: string|null}>
     */
    public function roomsFor(User $user): Collection
    {
        return $user
            ->conversations()
            ->select(['conversations.id', 'conversations.title', 'conversations.slug', 'conversations.type'])
            ->where('status', ConversationStatus::Active)
            ->whereIn('type', [ConversationType::General, ConversationType::Group])
            ->addSelect([
                'last_message' => Message::query()
                    ->select('body')
                    ->whereColumn('conversation_id', 'conversations.id')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(1),
            ])
            ->orderByRaw('case when type = ? then 0 else 1 end', [ConversationType::General->value])
            ->orderBy('title')
            ->get()
            ->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'title' => $conversation->title ?? 'Untitled room',
                'slug' => $conversation->slug,
                'type' => $conversation->type->value,
                'unread_count' => $conversation->pivot->unread_count,
                'last_message' => $conversation->last_message,
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{id: int, name: string, unread_count: int, last_message: string|null}>
     */
    public function directMessageUsersFor(User $user): Collection
    {
        // @TODO this is potential issue. When we will use other type of notifications not only DMs
        $unreadDirectNotifications = $user
            ->unreadNotifications()
            ->get()
            ->countBy(fn ($notification): int|string|null => $notification->data['sender_id'] ?? null);

        return User::query()
            ->select(['users.id', 'users.name'])
            ->whereKeyNot($user->id)
            ->addSelect([
                'last_direct_message' => Message::query()
                    ->select('messages.body')
                    ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                    ->join('conversation_participants as current_participant', 'current_participant.conversation_id', '=', 'conversations.id')
                    ->join('conversation_participants as other_participant', 'other_participant.conversation_id', '=', 'conversations.id')
                    ->where('conversations.type', ConversationType::Direct->value)
                    ->where('conversations.status', ConversationStatus::Active->value)
                    ->where('current_participant.user_id', $user->id)
                    ->whereColumn('other_participant.user_id', 'users.id')
                    ->orderByDesc('messages.created_at')
                    ->orderByDesc('messages.id')
                    ->limit(1),
                'unread_count' => Conversation::query()
                    ->select('current_participant.unread_count')
                    ->join('conversation_participants as current_participant', 'current_participant.conversation_id', '=', 'conversations.id')
                    ->join('conversation_participants as other_participant', 'other_participant.conversation_id', '=', 'conversations.id')
                    ->where('conversations.type', ConversationType::Direct->value)
                    ->where('conversations.status', ConversationStatus::Active->value)
                    ->where('current_participant.user_id', $user->id)
                    ->whereColumn('other_participant.user_id', 'users.id')
                    ->limit(1),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (User $directMessageUser): array => [
                'id' => $directMessageUser->id,
                'name' => $directMessageUser->name,
                'unread_count' => max(
                    $directMessageUser->unread_count ?? 0,
                    $unreadDirectNotifications->get($directMessageUser->id, 0),
                ),
                'last_message' => $directMessageUser->last_direct_message,
            ])
            ->values();
    }
}
