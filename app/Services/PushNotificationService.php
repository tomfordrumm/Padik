<?php

namespace App\Services;

use App\Jobs\SendWebPushNotification;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription as WebPushSubscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;
use Throwable;

class PushNotificationService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendToUser(User|int $user, array $payload): int
    {
        $user = $user instanceof User ? $user : User::query()->find($user);

        if (! $user instanceof User) {
            return 0;
        }

        $subscriptions = $user->pushSubscriptions()
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get();

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $subscriptions->each(
            fn (PushSubscription $subscription): mixed => SendWebPushNotification::dispatch(
                $subscription->user_id,
                $payload,
                $subscription->endpoint_hash,
            ),
        );

        return $subscriptions->count();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendToSubscription(string $endpointHash, array $payload): int
    {
        $subscription = PushSubscription::query()
            ->where('endpoint_hash', $endpointHash)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $subscription instanceof PushSubscription) {
            return 0;
        }

        return $this->sendToSubscriptions(new Collection([$subscription]), $payload);
    }

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array<string, mixed>  $payload
     */
    private function sendToSubscriptions(Collection $subscriptions, array $payload): int
    {
        $webPush = $this->makeWebPush();
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $subscriptions->each(function (PushSubscription $subscription) use ($webPush, $encodedPayload): void {
            $webPush->queueNotification(
                WebPushSubscription::create([
                    'endpoint' => $subscription->endpoint,
                    'keys' => [
                        'p256dh' => $subscription->public_key,
                        'auth' => $subscription->auth_token,
                    ],
                    'contentEncoding' => $subscription->content_encoding,
                ]),
                $encodedPayload,
            );
        });

        return $this->flush($webPush);
    }

    public function handleReport(MessageSentReport $report): bool
    {
        $subscription = PushSubscription::query()
            ->where('endpoint_hash', PushSubscription::endpointHash($report->getEndpoint()))
            ->first();

        if (! $subscription instanceof PushSubscription) {
            return false;
        }

        if ($report->isSubscriptionExpired()) {
            $subscription->delete();

            return false;
        }

        if ($report->isSuccess()) {
            $subscription->forceFill(['last_used_at' => now()])->save();

            return true;
        }

        return false;
    }

    private function flush(WebPush $webPush): int
    {
        $sent = 0;
        $transientFailures = [];

        foreach ($webPush->flush() as $report) {
            if ($this->handleReport($report)) {
                $sent++;

                continue;
            }

            if (! $report->isSubscriptionExpired()) {
                $transientFailures[] = $report->getEndpoint().': '.$report->getReason();
            }
        }

        if ($transientFailures !== []) {
            throw new RuntimeException('Web Push delivery failed for '.count($transientFailures).' subscription(s): '.implode('; ', $transientFailures));
        }

        return $sent;
    }

    private function makeWebPush(): WebPush
    {
        $subject = config('services.webpush.vapid.subject');
        $publicKey = config('services.webpush.vapid.public_key');
        $privateKey = config('services.webpush.vapid.private_key');

        if (! filled($subject) || ! filled($publicKey) || ! filled($privateKey)) {
            throw new RuntimeException('Web Push VAPID keys are not configured.');
        }

        try {
            return new WebPush(
                [
                    'VAPID' => [
                        'subject' => $subject,
                        'publicKey' => $publicKey,
                        'privateKey' => $privateKey,
                    ],
                ],
                [
                    'TTL' => (int) config('services.webpush.ttl', 3600),
                    'urgency' => config('services.webpush.urgency', 'normal'),
                ],
                (int) config('services.webpush.timeout', 10),
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to initialize Web Push delivery: '.$exception->getMessage(), previous: $exception);
        }
    }
}
