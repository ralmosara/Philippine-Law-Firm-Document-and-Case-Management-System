<?php

namespace App\Domain\Billing\Payments;

use App\Domain\Billing\Models\Invoice;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * PayMongo (https://developers.paymongo.com): hosted checkout for cards,
 * GCash, Maya and other Philippine payment methods.
 *
 * Card and wallet details are entered on PayMongo's page, never in this
 * application, which keeps the firm out of PCI DSS scope.
 */
class PayMongoGateway
{
    private const API = 'https://api.paymongo.com/v1';

    /** PayMongo's minimum charge: PHP 20.00. */
    public const MINIMUM_CENTS = 2000;

    public function isEnabled(): bool
    {
        return filled(config('services.paymongo.secret_key'));
    }

    /**
     * Open a hosted checkout session for the invoice's open balance.
     *
     * @return array{id: string, url: string}
     *
     * @throws RequestException
     */
    public function createCheckout(Invoice $invoice, string $successUrl, string $cancelUrl): array
    {
        $response = Http::withBasicAuth((string) config('services.paymongo.secret_key'), '')
            ->acceptJson()
            ->timeout(20)
            ->post(self::API.'/checkout_sessions', [
                'data' => [
                    'attributes' => [
                        'line_items' => [[
                            'name' => "Invoice {$invoice->number}",
                            'amount' => $invoice->balanceDue(),
                            'currency' => 'PHP',
                            'quantity' => 1,
                        ]],
                        'payment_method_types' => config('services.paymongo.payment_methods'),
                        'description' => "Invoice {$invoice->number} — {$invoice->matter?->reference}",
                        'reference_number' => $invoice->number,
                        'success_url' => $successUrl,
                        'cancel_url' => $cancelUrl,
                        'send_email_receipt' => true,
                        'show_description' => true,
                        'show_line_items' => true,
                        'metadata' => [
                            'invoice_id' => (string) $invoice->id,
                            'firm_id' => (string) $invoice->firm_id,
                        ],
                    ],
                ],
            ])
            ->throw();

        return [
            'id' => (string) $response->json('data.id'),
            'url' => (string) $response->json('data.attributes.checkout_url'),
        ];
    }

    /**
     * Verify the `Paymongo-Signature` header: "t=<timestamp>,te=<test sig>,li=<live sig>",
     * where each signature is HMAC-SHA256 of "<timestamp>.<raw body>".
     */
    public function hasValidSignature(string $payload, ?string $header): bool
    {
        $secret = (string) config('services.paymongo.webhook_secret');

        if ($secret === '' || blank($header)) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key] = $value;
        }

        if (($parts['t'] ?? '') === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $parts['t'].'.'.$payload, $secret);
        $given = ($parts['li'] ?? '') !== '' ? $parts['li'] : ($parts['te'] ?? '');

        return $given !== '' && hash_equals($expected, $given);
    }
}
