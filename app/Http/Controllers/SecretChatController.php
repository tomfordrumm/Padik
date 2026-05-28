<?php

namespace App\Http\Controllers;

use App\Actions\Conversations\EnsureSecretConversation;
use App\Enums\ConversationType;
use App\Enums\InvitationStatus;
use App\Models\Conversation;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\SecretChatInvitationReceived;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecretChatController extends Controller
{
    public function store(Request $request, User $user, EnsureSecretConversation $secretConversation): RedirectResponse
    {
        $conversation = $secretConversation->between($request->user(), $user);
        $invitation = Invitation::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $request->user()->id,
            'user_id' => $user->id,
            'status' => InvitationStatus::Pending,
        ]);

        $user->notify(new SecretChatInvitationReceived($invitation));

        return to_route('secret-chats.show', ['conversation' => $conversation->slug]);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        abort_unless($conversation->type === ConversationType::Secret, 404);
        abort_unless($request->user()->conversations()->whereKey($conversation->id)->exists(), 403);

        $participants = $conversation
            ->users()
            ->select(['users.id', 'users.name'])
            ->get();

        $peer = $participants->first(fn (User $participant): bool => ! $participant->is($request->user()))
            ?? $conversation->invitations()
                ->with('user:id,name')
                ->where('sender_id', $request->user()->id)
                ->latest()
                ->first()
                ?->user;

        return Inertia::render('Conversation', [
            'currentRoom' => [
                'id' => $conversation->id,
                'title' => $peer?->name ?? 'Secret chat',
                'slug' => $conversation->slug,
                'type' => $conversation->type->value,
                'direct_user_id' => $peer?->id,
            ],
            'messages' => [],
            'secretChat' => [
                'participants' => $participants->map(fn (User $participant): array => [
                    'id' => $participant->id,
                    'name' => $participant->name,
                    'public_key' => $participant->pivot->secret_public_key,
                    'fingerprint' => $participant->pivot->secret_key_fingerprint,
                ])->values(),
            ],
        ]);
    }
}
