<?php

namespace App\Http\Middleware;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'rooms' => fn () => $request->user()
                ? $request->user()
                    ->conversations()
                    ->where('status', ConversationStatus::Active)
                    ->orderByRaw('case when type = ? then 0 else 1 end', ['general'])
                    ->orderBy('title')
                    ->get(['conversations.id', 'conversations.title', 'conversations.slug', 'conversations.type'])
                    ->map(fn (Conversation $conversation): array => [
                        'id' => $conversation->id,
                        'title' => $conversation->title ?? 'Untitled room',
                        'slug' => $conversation->slug,
                        'type' => $conversation->type->value,
                        'unread_count' => $conversation->pivot->unread_count,
                    ])
                    ->values()
                : [],
            'directMessageUsers' => fn () => $request->user()
                ? User::query()
                    ->whereKeyNot($request->user()->id)
                    ->orderBy('name')
                    ->get(['id', 'name', 'email'])
                    ->map(fn (User $user): array => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ])
                    ->values()
                : [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
