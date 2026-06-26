<?php

namespace App\Contracts;

use App\Models\User;
use App\Services\Push\PushNotificationPayload;

interface PushNotificationDispatcher
{
    public function dispatch(User $recipient, PushNotificationPayload $payload): void;
}
