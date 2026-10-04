<?php

namespace App\Domain\EInvoicing\Transmitters;

use App\Domain\EInvoicing\EInvoice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Posts the e-invoice as JSON to a provider's HTTPS endpoint: for a
 * provider (or a small adapter service in front of one) that accepts the
 * neutral document. The body is signed with a shared secret
 * (X-Signature: sha256=HMAC), and the e-invoice's id is sent as the
 * Idempotency-Key, so a retry can never file the same invoice twice.
 *
 * Expected answer: 2xx with {"status": "accepted"|"submitted", "reference": "..."};
 * 4xx with {"message": "..."} for a rejection (fix the data, then retry).
 * Anything else, or no answer, is retried.
 */
class HttpTransmitter implements Transmitter
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $token,
        private readonly string $secret,
    ) {
        if (! str_starts_with($endpoint, 'https://')) {
            throw new RuntimeException('The e-invoicing endpoint must use HTTPS.');
        }
    }

    public function name(): string
    {
        return 'http';
    }

    public function transmit(EInvoice $eInvoice): Result
    {
        $body = json_encode($eInvoice->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        try {
            $response = Http::withToken($this->token)
                ->withHeaders([
                    'Idempotency-Key' => "lexph-einvoice-{$eInvoice->id}",
                    'X-Signature' => 'sha256='.hash_hmac('sha256', $body, $this->secret),
                ])
                ->timeout(20)
                ->withBody($body, 'application/json')
                ->post($this->endpoint);
        } catch (ConnectionException $e) {
            throw new RuntimeException('The e-invoicing provider could not be reached: '.$e->getMessage(), previous: $e);
        }

        $reference = $response->json('reference');
        $reference = is_scalar($reference) ? mb_substr((string) $reference, 0, 191) : null;

        if ($response->successful()) {
            return $response->json('status') === 'accepted' ? Result::accepted($reference) : Result::submitted($reference);
        }

        if ($response->clientError() && $response->status() !== 429) {
            $message = $response->json('message');

            return Result::rejected(mb_substr(is_string($message) ? $message : "Rejected (HTTP {$response->status()}).", 0, 2000), $reference);
        }

        throw new RuntimeException("The e-invoicing provider answered HTTP {$response->status()}.");
    }
}
