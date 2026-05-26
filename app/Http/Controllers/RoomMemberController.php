<?php

namespace App\Http\Controllers;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RoomMemberController extends Controller
{
    public function destroy(Request $request, Conversation $conversation, User $user): RedirectResponse
    {
        abort_unless(
            $conversation->type === ConversationType::Group
                && $conversation->created_by_id === $request->user()->id
                && $user->id !== $conversation->created_by_id
                && $conversation->users()->whereKey($user->id)->exists(),
            404,
        );

        $conversation->users()->detach($user->id);

        return back();
    }
}
