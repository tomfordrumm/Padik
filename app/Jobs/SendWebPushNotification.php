<?php

namespace App\Jobs;

use App\Services\PushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWebPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $recipientId,
        public array $payload,
        public ?string $endpointHash = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PushNotificationService $pushNotifications): void
    {
        if ($this->endpointHash) {
            $pushNotifications->sendToSubscription($this->endpointHash, $this->payload);

            return;
        }

        $pushNotifications->sendToUser($this->recipientId, $this->payload);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 5, 10];
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Web Push notification delivery failed.', [
            'recipient_id' => $this->recipientId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
