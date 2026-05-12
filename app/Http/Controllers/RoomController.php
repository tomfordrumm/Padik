<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    public function __invoke(Request $request, Conversation $conversation): Response
    {
        abort_unless(
            $request->user()?->conversations()->whereKey($conversation->id)->exists(),
            404,
        );

        return Inertia::render('Dashboard', [
            'currentRoom' => [
                'id' => $conversation->id,
                'title' => $conversation->title ?? 'Untitled room',
                'slug' => $conversation->slug,
                'type' => $conversation->type->value,
            ],
            'messages' => $conversation->messages()
                ->with('user:id,name,email')
                ->oldest()
                ->get()
                ->map(fn ($message): array => [
                    'id' => $message->id,
                    'sender_id' => $message->user_id,
                    'author' => $message->user->name,
                    'body' => $message->body,
                    'time' => $message->created_at->format('H:i'),
                    'own' => (int) $message->user_id === (int) $request->user()->id,
                ]),
        ]);
    }
}
