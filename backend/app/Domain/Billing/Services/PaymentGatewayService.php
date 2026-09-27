<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\TrustLedger;
use Illuminate\Support\Facades\Log;

class PaymentGatewayService
{
    /**
     * Process an online payment (Simulated PayMongo / Xendit Integration)
     *
     * @param  string  $paymentMethod  e.g. 'gcash', 'paymaya', 'card'
     */
    public function processPayment(int $amountCents, string $paymentMethod, int $trustAccountId): array
    {
        // In a real application, this would make an HTTP request to PayMongo API
        // using config('services.paymongo.secret_key')

        Log::info("Processing simulated {$paymentMethod} payment for amount: {$amountCents} cents");

        // Simulate API network delay
        usleep(500000); // 0.5 seconds

        // Record successful payment to the Trust Ledger automatically
        $ledger = TrustLedger::create([
            'trust_account_id' => $trustAccountId,
            'transaction_date' => now(),
            'transaction_type' => 'deposit',
            'amount' => $amountCents / 100, // Convert cents to standard currency
            'description' => 'Online Payment via '.strtoupper($paymentMethod),
        ]);

        return [
            'success' => true,
            'transaction_id' => 'tx_mock_'.uniqid(),
            'message' => 'Payment processed successfully.',
            'ledger_entry_id' => $ledger->id,
        ];
    }
}
