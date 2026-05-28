<?php

namespace App\Http\Controllers;

use App\Actions\Conversations\EnsureDirectConversation;
use App\Http\Resources\MessageData;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DirectMessageController extends Controller
{
    public function __invoke(Request $request, User $user, EnsureDirectConversation $directConversation): Response
    {
        $conversation = $directConversation->between($request->user(), $user);

        $firstUnreadMessageId = $conversation->firstUnreadMessageIdFor($request->user());

        $conversation->markReadFor($request->user());

        $request->user()
            ->unreadNotifications()
            ->where('data->sender_id', $user->id)
            ->update(['read_at' => now()]);

        return Inertia::render('Conversation', [
            'currentRoom' => [
                'id' => $conversation->id,
                'title' => $user->name,
                'slug' => $conversation->slug,
                'type' => $conversation->type->value,
                'direct_user_id' => $user->id,
            ],
            'messages' => $conversation->messages()
                ->with('user:id,name,email')
                ->oldest()
                ->get()
                ->map(fn ($message): array => MessageData::fromMessage($message, $request->user())),
            'firstUnreadMessageId' => $firstUnreadMessageId,
            'mentionableUsers' => [[
                'id' => $user->id,
                'name' => $user->name,
            ]],
        ]);
    }
}
