<?php

namespace App\Http\Controllers;

use App\Enums\ConversationParticipantRole;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

class InvitationResponseController extends Controller
{
    public function accept(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $this->authorizeNotification($request, $notification);

        /** @var Invitation $invitation */
        $invitation = Invitation::query()
            ->with('conversation')
            ->whereKey($notification->data['invitation_id'] ?? null)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        DB::transaction(function () use ($request, $notification, $invitation): void {
            if ($invitation->status === InvitationStatus::Pending) {
                $invitation->update([
                    'status' => InvitationStatus::Accepted,
                    'responded_at' => now(),
                ]);

                $invitation->conversation->users()->syncWithoutDetaching([
                    $request->user()->id => [
                        'role' => ConversationParticipantRole::Member->value,
                        'last_read_at' => now(),
                    ],
                ]);
            }

            $notification->markAsRead();
        });

        return to_route('rooms.show', ['conversation' => $invitation->conversation->slug]);
    }

    public function decline(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $this->authorizeNotification($request, $notification);

        /** @var Invitation|null $invitation */
        $invitation = Invitation::query()
            ->whereKey($notification->data['invitation_id'] ?? null)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($invitation?->status === InvitationStatus::Pending) {
            $invitation->update([
                'status' => InvitationStatus::Declined,
                'responded_at' => now(),
            ]);
        }

        $notification->markAsRead();

        return back();
    }

    private function authorizeNotification(Request $request, DatabaseNotification $notification): void
    {
        abort_unless(
            $notification->notifiable_type === $request->user()::class
                && (int) $notification->notifiable_id === $request->user()->id,
            404,
        );
    }
}
