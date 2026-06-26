<?php

namespace App\Services\Push;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;

class ActivePushConversationService
{
    private const TtlSeconds = 90;

    public function __construct(private Repository $cache) {}

    public function markViewing(User $user, Conversation $conversation, string $sessionId): void
    {
        $this->withLock($user, $conversation, function () use ($user, $conversation, $sessionId): void {
            $sessions = $this->activeSessions($user, $conversation);
            $sessions[$sessionId] = now()->addSeconds(self::TtlSeconds)->toIso8601String();

            $this->cache->put(
                $this->key($user, $conversation),
                $sessions,
                now()->addSeconds(self::TtlSeconds + 5),
            );
        });
    }

    public function clearViewing(User $user, Conversation $conversation, string $sessionId): void
    {
        $this->withLock($user, $conversation, function () use ($user, $conversation, $sessionId): void {
            $sessions = $this->activeSessions($user, $conversation);
            unset($sessions[$sessionId]);

            if ($sessions === []) {
                $this->cache->forget($this->key($user, $conversation));

                return;
            }

            $this->cache->put(
                $this->key($user, $conversation),
                $sessions,
                now()->addSeconds(self::TtlSeconds + 5),
            );
        });
    }

    public function isViewing(User $user, Conversation $conversation): bool
    {
        return $this->activeSessions($user, $conversation) !== [];
    }

    /**
     * @return array<string, string>
     */
    private function activeSessions(User $user, Conversation $conversation): array
    {
        $expiresAtBySession = $this->cache->get($this->key($user, $conversation), []);

        if (! is_array($expiresAtBySession)) {
            return [];
        }

        return collect($expiresAtBySession)
            ->filter(fn (mixed $expiresAt): bool => is_string($expiresAt)
                && Carbon::parse($expiresAt)->isFuture())
            ->mapWithKeys(fn (string $expiresAt, string $sessionId): array => [$sessionId => $expiresAt])
            ->all();
    }

    private function key(User $user, Conversation $conversation): string
    {
        return "push-presence:user:{$user->id}:conversation:{$conversation->id}";
    }

    private function lockKey(User $user, Conversation $conversation): string
    {
        return $this->key($user, $conversation).':lock';
    }

    private function withLock(User $user, Conversation $conversation, callable $callback): void
    {
        try {
            $this->cache
                ->lock($this->lockKey($user, $conversation), 5)
                ->block(1, $callback);
        } catch (LockTimeoutException) {
            $callback();
        }
    }
}
