<?php

namespace Tests\Unit;

use App\Jobs\SendWebPushNotification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\MessageSentReport;
use Mockery;
use Tests\TestCase;

class PushNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_web_push_report_deletes_subscription(): void
    {
        $endpoint = 'https://push.example.test/subscriptions/expired-device';
        $subscription = PushSubscription::factory()->for(User::factory())->create([
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::endpointHash($endpoint),
        ]);

        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('getEndpoint')->andReturn($endpoint);
        $report->shouldReceive('isSubscriptionExpired')->andReturn(true);

        $handled = app(PushNotificationService::class)->handleReport($report);

        $this->assertFalse($handled);
        $this->assertModelMissing($subscription);
    }

    public function test_successful_web_push_report_updates_last_used_at(): void
    {
        $this->travelTo(now()->startOfSecond());

        $endpoint = 'https://push.example.test/subscriptions/successful-device';
        $subscription = PushSubscription::factory()->for(User::factory())->create([
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::endpointHash($endpoint),
            'last_used_at' => null,
        ]);

        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('getEndpoint')->andReturn($endpoint);
        $report->shouldReceive('isSubscriptionExpired')->andReturn(false);
        $report->shouldReceive('isSuccess')->andReturn(true);

        $handled = app(PushNotificationService::class)->handleReport($report);

        $this->assertTrue($handled);
        $this->assertTrue($subscription->fresh()->last_used_at->isSameSecond(now()));
    }

    public function test_missing_recipient_is_a_noop(): void
    {
        $sent = app(PushNotificationService::class)->sendToUser(12345, [
            'type' => 'direct_message',
            'title' => 'New direct message',
        ]);

        $this->assertSame(0, $sent);
    }

    public function test_user_delivery_fans_out_to_endpoint_specific_jobs(): void
    {
        Queue::fake([SendWebPushNotification::class]);

        $user = User::factory()->create();
        $first = PushSubscription::factory()->for($user)->create();
        $second = PushSubscription::factory()->for($user)->create();

        $queued = app(PushNotificationService::class)->sendToUser($user, [
            'type' => 'direct_message',
            'title' => 'New direct message',
        ]);

        $this->assertSame(2, $queued);

        Queue::assertPushed(
            SendWebPushNotification::class,
            fn (SendWebPushNotification $job): bool => $job->recipientId === $user->id
                && $job->endpointHash === $first->endpoint_hash
        );
        Queue::assertPushed(
            SendWebPushNotification::class,
            fn (SendWebPushNotification $job): bool => $job->recipientId === $user->id
                && $job->endpointHash === $second->endpoint_hash
        );
    }
}
