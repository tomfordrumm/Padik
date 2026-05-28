<?php

namespace App\Http\Controllers;

use App\Enums\ConversationType;
use App\Enums\InvitationStatus;
use App\Http\Requests\StoreRoomInvitationRequest;
use App\Models\Conversation;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\RoomInvitationReceived;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoomInvitationController extends Controller
{
    public function store(StoreRoomInvitationRequest $request, Conversation $conversation): RedirectResponse
    {
        $sender = $request->user();
        $userIds = collect($request->validated('user_ids'))
            ->map(fn (int|string $userId): int => (int) $userId)
            ->unique()
            ->values();

        $userIds->each(function (int $userId) use ($conversation, $sender): void {
            $invitation = Invitation::query()->firstOrCreate(
                [
                    'conversation_id' => $conversation->id,
                    'user_id' => $userId,
                    'status' => InvitationStatus::Pending,
                ],
                [
                    'sender_id' => $sender->id,
                ],
            );

            if ($invitation->wasRecentlyCreated) {
                User::query()
                    ->findOrFail($userId)
                    ->notify(new RoomInvitationReceived($invitation));
            }
        });

        $previousPath = parse_url(url()->previous(), PHP_URL_PATH);

        if ($previousPath === route('rooms.settings.edit', $conversation, false)
            || Str::startsWith($previousPath, '/users/')
        ) {
            return back();
        }

        return to_route('rooms.show', ['conversation' => $conversation->slug]);
    }

    public function destroy(Request $request, Conversation $conversation, Invitation $invitation): RedirectResponse
    {
        abort_unless(
            $conversation->type === ConversationType::Group
                && $conversation->created_by_id === $request->user()->id
                && $invitation->conversation_id === $conversation->id
                && $invitation->status === InvitationStatus::Pending,
            404,
        );

        $invitation->update([
            'status' => InvitationStatus::Cancelled,
            'responded_at' => now(),
        ]);

        return back();
    }
}
