<?php

namespace App\Notifications\Channels;

final readonly class SmsMessage
{
    public function __construct(public string $content) {}
}
