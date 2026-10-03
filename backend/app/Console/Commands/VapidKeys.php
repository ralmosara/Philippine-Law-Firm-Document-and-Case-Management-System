<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/** Prints a new key pair for phone and browser notifications (Web Push). */
class VapidKeys extends Command
{
    protected $signature = 'ops:vapid-keys';

    protected $description = 'Generate the VAPID key pair for phone and browser notifications';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->line('Add these to the server .env (keep the private key secret), then restart:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:you@yourfirm.ph');
        $this->newLine();
        $this->warn('Generate them once: new keys mean everyone turns notifications on again.');

        return self::SUCCESS;
    }
}
