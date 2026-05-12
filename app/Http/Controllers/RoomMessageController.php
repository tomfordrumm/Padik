<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoomMessageRequest;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;

class RoomMessageController extends Controller
{
    public function store(StoreRoomMessageRequest $request, Conversation $conversation): RedirectResponse
    {
        $conversation->messages()->create([
            'user_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        return to_route('rooms.show', ['conversation' => $conversation->slug]);
    }
}
