<?php

namespace App\Notifications\Channels;

use App\Services\Notifications\SmsService;
use Illuminate\Notifications\Notification;
use RuntimeException;

/**
 * Notification channel for SMS. Notifications implement toSms() and
 * notifiables implement routeNotificationForSms().
 */
class SmsChannel
{
    public function __construct(private readonly SmsService $sms) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $number = $notifiable->routeNotificationFor('sms', $notification);

        if (! $number || ! method_exists($notification, 'toSms')) {
            return;
        }

        if (! $this->sms->send($number, $notification->toSms($notifiable)->content)) {
            // Surface the failure so the queue retries the notification.
            throw new RuntimeException('SMS gateway rejected the message.');
        }
    }
}
