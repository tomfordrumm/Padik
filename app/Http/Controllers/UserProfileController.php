<?php

namespace App\Http\Controllers;

use App\Enums\ConversationType;
use App\Enums\InvitationStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserProfileController extends Controller
{
    public function __invoke(Request $request, User $user): Response
    {
        return Inertia::render('UserProfile', [
            'profileUser' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
            'invitableRooms' => $request->user()
                ->conversations()
                ->select(['conversations.id', 'conversations.title', 'conversations.slug'])
                ->where('type', ConversationType::Group)
                ->whereNotIn(
                    'conversations.id',
                    $user->conversations()->select('conversations.id')
                )
                ->whereNotIn(
                    'conversations.id',
                    $user->receivedInvitations()
                        ->where('status', InvitationStatus::Pending)
                        ->select('conversation_id')
                )
                ->orderBy('title')
                ->get()
                ->map(fn ($conversation): array => [
                    'id' => $conversation->id,
                    'title' => $conversation->title ?? 'Untitled room',
                    'slug' => $conversation->slug,
                ])
                ->values(),
        ]);
    }
}
