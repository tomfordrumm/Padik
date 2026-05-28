<?php

namespace App\Http\Controllers;

use App\Enums\ConversationType;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RoomMembershipController extends Controller
{
    public function destroy(Request $request, Conversation $conversation): RedirectResponse
    {
        abort_unless(
            $conversation->type === ConversationType::Group
                && $conversation->created_by_id !== $request->user()->id
                && $request->user()->conversations()->whereKey($conversation->id)->exists(),
            404,
        );

        $conversation->users()->detach($request->user()->id);

        return to_route('rooms.show', ['conversation' => 'general']);
    }
}
