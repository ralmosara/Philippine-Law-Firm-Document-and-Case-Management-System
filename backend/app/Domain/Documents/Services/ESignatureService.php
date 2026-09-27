<?php

namespace App\Domain\Documents\Services;

use App\Domain\Documents\Models\Document;
use Illuminate\Support\Facades\Log;

class ESignatureService
{
    protected string $provider;

    public function __construct(string $provider = 'mock')
    {
        $this->provider = $provider; // e.g., 'docusign', 'hellosign', 'mock'
    }

    /**
     * Send a document to a client for electronic signature.
     */
    public function sendForSignature(Document $document, string $signerEmail, string $signerName): array
    {
        if ($this->provider === 'mock') {
            Log::info("Mock E-Signature requested for Document ID: {$document->id} to {$signerEmail}");

            // Update document status
            $document->update(['status' => 'pending_signature']);

            return [
                'success' => true,
                'envelope_id' => 'mock-env-'.uniqid(),
                'status' => 'sent',
                'message' => 'Document successfully queued for signature simulation.',
                'signing_url' => 'http://localhost:8000/mock-sign/'.$document->id,
            ];
        }

        // Future integration point for DocuSign SDK
        throw new \Exception("E-Signature provider '{$this->provider}' is not fully implemented yet.");
    }

    /**
     * Webhook receiver for when a document is signed.
     */
    public function handleWebhook(array $payload)
    {
        // Logic to parse webhook from DocuSign and mark Document as 'signed'
        // and optionally save the finalized signed PDF version back to storage.
    }
}
