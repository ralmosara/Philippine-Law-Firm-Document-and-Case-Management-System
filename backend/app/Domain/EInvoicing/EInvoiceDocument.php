<?php

namespace App\Domain\EInvoicing;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Matters\Models\Firm;

/**
 * The e-invoice for an issued invoice (or its cancellation), as a neutral
 * document holding what the BIR requires on an invoice for services: the
 * seller's and buyer's registered names, TINs and addresses, the invoice
 * number and date, each line, and the sales broken down by VAT treatment.
 *
 * This is not the BIR EIS wire format: each provider's transmitter maps it
 * to the schema, signing and encryption that provider (or the BIR) expects.
 * Amounts are decimal strings in pesos, so nothing is lost to floats.
 */
class EInvoiceDocument
{
    public const VERSION = 1;

    public static function forInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['client', 'lines', 'matter']);
        $firm = Firm::findOrFail($invoice->firm_id);
        $fees = $invoice->subtotal_cents;

        return [
            'format' => 'lexph-einvoice',
            'version' => self::VERSION,
            'document_type' => 'invoice',
            'invoice_number' => $invoice->number,
            'issue_date' => $invoice->issued_at?->toDateString(),
            'due_date' => $invoice->due_at?->toDateString(),
            'currency' => 'PHP',
            'seller' => self::seller($firm),
            'buyer' => [
                'registered_name' => $invoice->client->name,
                'tin' => self::tin($invoice->client->tin),
                'address' => $invoice->client->address,
                'type' => $invoice->client->type,
            ],
            'reference' => $invoice->matter ? ['matter' => $invoice->matter->reference, 'title' => $invoice->matter->title] : null,
            'lines' => $invoice->lines->map(fn (InvoiceLine $line) => [
                'kind' => $line->kind,                    // fee | time | expense
                'description' => $line->description,
                'quantity' => $line->minutes ? self::decimal(intdiv($line->minutes * 100, 60)) : '1.00',
                'unit' => $line->minutes ? 'hour' : 'service',
                'unit_price' => $line->rate_cents ? self::decimal($line->rate_cents) : self::decimal($line->amount_cents),
                'amount' => self::decimal($line->amount_cents),
            ])->values()->all(),
            'totals' => [
                // A VAT-registered firm's fees are VATable; a non-VAT firm's are not subject to VAT (percentage tax applies).
                'vatable_sales' => self::decimal($firm->vat_registered ? $fees : 0),
                'vat_amount' => self::decimal($invoice->vat_cents),
                'non_vat_sales' => self::decimal($firm->vat_registered ? 0 : $fees),
                'vat_exempt_sales' => '0.00',
                'zero_rated_sales' => '0.00',
                // Costs advanced for the client (filing fees and the like), billed at cost.
                'reimbursable_expenses' => self::decimal($invoice->expenses_cents),
                'total_amount' => self::decimal($invoice->total_cents),
            ],
        ];
    }

    public static function forCancellation(Invoice $invoice, EInvoice $original): array
    {
        $firm = Firm::findOrFail($invoice->firm_id);

        return [
            'format' => 'lexph-einvoice',
            'version' => self::VERSION,
            'document_type' => 'cancellation',
            'invoice_number' => $invoice->number,
            'issue_date' => $invoice->issued_at?->toDateString(),
            'cancelled_on' => now()->timezone('Asia/Manila')->toDateString(),
            'seller' => self::seller($firm),
            'original' => ['provider_reference' => $original->provider_reference, 'payload_sha256' => $original->payload_sha256],
        ];
    }

    private static function seller(Firm $firm): array
    {
        return [
            'registered_name' => $firm->name,
            'tin' => self::tin($firm->tin),
            'branch_code' => $firm->tin_branch_code ?: '00000',
            'address' => $firm->address,
            'vat_registered' => (bool) $firm->vat_registered,
        ];
    }

    /** "123-456-789-000" → "123456789000"; null when missing. */
    private static function tin(?string $tin): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $tin);

        return $digits !== '' ? $digits : null;
    }

    /** 123456 → "1234.56", in integers (no float rounding). */
    private static function decimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}
