<?php

namespace App\Services\Push;

use App\Contracts\PushNotificationDispatcher;
use App\Jobs\SendWebPushNotification;
use App\Models\User;

class QueuePushNotificationDispatcher implements PushNotificationDispatcher
{
    public function dispatch(User $recipient, PushNotificationPayload $payload): void
    {
        SendWebPushNotification::dispatch($recipient->id, $payload->toArray());
    }
}
