<?php

namespace App\Http\Controllers;

use App\Enums\ConversationType;
use App\Enums\InvitationStatus;
use App\Http\Requests\UpdateRoomRequest;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoomSettingsController extends Controller
{
    public function edit(Request $request, Conversation $conversation): Response
    {
        abort_unless(
            $conversation->type === ConversationType::Group
                && $conversation->created_by_id === $request->user()->id,
            404,
        );

        return Inertia::render('RoomSettings', [
            'room' => [
                'id' => $conversation->id,
                'title' => $conversation->title ?? 'Untitled room',
                'slug' => $conversation->slug,
            ],
            'members' => $conversation->users()
                ->select(['users.id', 'users.name', 'users.email'])
                ->orderBy('users.name')
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->pivot->role,
                    'is_owner' => $user->id === $conversation->created_by_id,
                ])
                ->values(),
            'pendingInvitations' => $conversation->invitations()
                ->with('user:id,name,email')
                ->where('status', InvitationStatus::Pending)
                ->latest()
                ->get()
                ->map(fn ($invitation): array => [
                    'id' => $invitation->id,
                    'user_id' => $invitation->user_id,
                    'name' => $invitation->user->name,
                    'email' => $invitation->user->email,
                    'created_at_human' => $invitation->created_at->diffForHumans(),
                ])
                ->values(),
            'availableUsers' => User::query()
                ->select(['id', 'name', 'email'])
                ->whereKeyNot($conversation->users()->pluck('users.id'))
                ->whereNotIn(
                    'id',
                    $conversation->invitations()
                        ->where('status', InvitationStatus::Pending)
                        ->select('user_id')
                )
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->values(),
        ]);
    }

    public function update(UpdateRoomRequest $request, Conversation $conversation): RedirectResponse
    {
        $conversation->update($request->validated());

        return to_route('rooms.show', ['conversation' => $conversation->slug]);
    }
}
