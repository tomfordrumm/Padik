<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

#[Signature('push:vapid-generate {--subject= : VAPID subject URL or mailto address}')]
#[Description('Generate VAPID keys for Web Push notifications')]
class GenerateVapidKeys extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->ensureOpenSslRandomStatePath();

        $subject = $this->option('subject')
            ?: config('services.webpush.vapid.subject')
            ?: 'mailto:admin@example.com';

        $keys = VAPID::createVapidKeys();

        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT='.$subject);

        return self::SUCCESS;
    }

    private function ensureOpenSslRandomStatePath(): void
    {
        if (getenv('RANDFILE') !== false) {
            return;
        }

        putenv('RANDFILE='.storage_path('framework/cache/openssl-random-state'));
    }
}
