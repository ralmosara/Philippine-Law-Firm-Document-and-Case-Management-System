<?php

namespace App\Domain\Push\Notifications;

use Illuminate\Notifications\Notification;

/** "Notifications work on this phone": sent from the Profile page. */
class TestPush extends Notification
{
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'test', 'title' => 'Notifications work on this device', 'body' => 'Deadlines, hearings and assignments will appear here.', 'url' => '/profile'];
    }
}
