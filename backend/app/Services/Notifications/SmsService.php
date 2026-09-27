<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends SMS through Semaphore (https://semaphore.co), a Philippine gateway.
 * With no API key configured, messages are written to the log instead, so
 * local development and tests never send real texts.
 */
class SmsService
{
    public function send(string $mobileNumber, string $message): bool
    {
        $apiKey = config('services.semaphore.api_key');

        if (empty($apiKey)) {
            Log::info('SMS (not sent: no Semaphore API key configured)', ['to' => $mobileNumber, 'message' => $message]);

            return true;
        }

        $response = Http::asForm()
            ->timeout(10)
            ->post('https://api.semaphore.co/api/v4/messages', [
                'apikey' => $apiKey,
                'number' => $mobileNumber,
                'message' => $message,
                'sendername' => config('services.semaphore.sender_name'),
            ]);

        if ($response->failed()) {
            Log::warning('Semaphore SMS failed', ['to' => $mobileNumber, 'status' => $response->status()]);
        }

        return $response->successful();
    }
}
