<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestroyPushSubscriptionRequest;
use App\Http\Requests\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class PushSubscriptionController extends Controller
{
    public function key(): JsonResponse
    {
        $publicKey = config('services.webpush.vapid.public_key');

        abort_unless(filled($publicKey), 503, 'Web Push VAPID public key is not configured.');

        return response()->json([
            'public_key' => $publicKey,
        ]);
    }

    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $endpoint = $validated['endpoint'];

        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::endpointHash($endpoint)],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $endpoint,
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
                'content_encoding' => $validated['content_encoding'] ?? 'aes128gcm',
                'user_agent' => $validated['user_agent'] ?? $request->userAgent(),
                'last_used_at' => now(),
                'expires_at' => isset($validated['expiration_time'])
                    ? Carbon::parse($validated['expiration_time'])
                    : null,
            ],
        );

        return response()->json([
            'enabled' => true,
        ]);
    }

    public function destroy(DestroyPushSubscriptionRequest $request): JsonResponse
    {
        PushSubscription::query()
            ->whereBelongsTo($request->user())
            ->where('endpoint_hash', PushSubscription::endpointHash($request->validated('endpoint')))
            ->delete();

        return response()->json([
            'enabled' => false,
        ]);
    }
}
