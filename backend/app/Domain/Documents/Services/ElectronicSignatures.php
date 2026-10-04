<?php

namespace App\Domain\Documents\Services;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\SignatureStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\SignatureRequest;
use App\Domain\Documents\Notifications\SignatureAnswered;
use App\Domain\Documents\Notifications\SignatureRequested;
use App\Domain\Matters\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Built-in electronic signatures for client documents.
 *
 * An electronic signature is valid under the E-Commerce Act (RA 8792,
 * Sec. 8) when the method identifies the signer, shows their assent, and
 * is reliable for the purpose. Here that is: the client's own authenticated
 * portal session; an explicit consent step with a drawn or typed signature;
 * and the SHA-256 of the exact content shown, checked again at signing, with
 * time, IP address and browser recorded.
 *
 * For instruments that must be notarized, e-signature does not replace
 * personal appearance before the notary.
 */
class ElectronicSignatures
{
    public function request(Document $document, User $by, ?string $message = null, ?int $expiresInDays = 14): SignatureRequest
    {
        $document->loadMissing(['matter.client', 'latestVersion']);
        $client = $document->matter->client;

        if ($document->status !== DocumentStatus::Final) {
            throw ValidationException::withMessages(['document' => 'Mark the document final before asking for a signature.']);
        }

        if ($client === null || ! $client->portal_enabled || blank($client->email)) {
            throw ValidationException::withMessages(['document' => 'The client needs portal access before they can sign electronically.']);
        }

        $request = DB::transaction(function () use ($document, $by, $message, $expiresInDays, $client) {
            $version = $document->latestVersion;

            $request = SignatureRequest::create([
                'firm_id' => $document->firm_id,
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'client_id' => $client->id,
                'requested_by' => $by->id,
                'message' => $message,
                'content_sha256' => hash('sha256', $version->content),
                'expires_at' => $expiresInDays ? now()->addDays($expiresInDays)->endOfDay() : null,
            ]);

            $document->forceFill(['status' => DocumentStatus::PendingSignature, 'shared_with_client' => true])->save();

            return $request;
        });

        $client->notify(new SignatureRequested($request->load(['document', 'requester'])));

        return $request;
    }

    public function cancel(SignatureRequest $request): SignatureRequest
    {
        return DB::transaction(function () use ($request) {
            $request = $this->lockPending($request, allowExpired: true);
            $request->forceFill(['status' => SignatureStatus::Cancelled, 'responded_at' => now()])->save();
            $this->returnToFinal($request);

            return $request;
        });
    }

    /**
     * @param  'drawn'|'typed'  $method
     */
    public function sign(SignatureRequest $request, Client $client, string $signerName, string $method, ?string $image, ?string $ip, ?string $userAgent): SignatureRequest
    {
        $request = DB::transaction(function () use ($request, $client, $signerName, $method, $image, $ip, $userAgent) {
            $request = $this->lockPending($request, $client);
            $content = $request->version()->value('content');

            // The content must be byte-for-byte what the request was made for.
            if ($content === null || ! hash_equals($request->content_sha256, hash('sha256', $content))) {
                throw ValidationException::withMessages(['document' => __('This document has changed since signing was requested. Please contact your lawyer.')]);
            }

            $request->forceFill([
                'status' => SignatureStatus::Signed,
                'responded_at' => now(),
                'signer_name' => $signerName,
                'signature_method' => $method,
                'signature_image' => $method === 'drawn' ? $image : null,
                'signer_ip' => $ip,
                'signer_user_agent' => $userAgent ? mb_substr($userAgent, 0, 512) : null,
            ])->save();

            Document::whereKey($request->document_id)->firstOrFail()
                ->forceFill(['status' => DocumentStatus::Signed])->save();

            return $request;
        });

        $this->notifyRequester($request);

        return $request;
    }

    public function decline(SignatureRequest $request, Client $client, ?string $reason, ?string $ip, ?string $userAgent): SignatureRequest
    {
        $request = DB::transaction(function () use ($request, $client, $reason, $ip, $userAgent) {
            $request = $this->lockPending($request, $client);
            $request->forceFill([
                'status' => SignatureStatus::Declined,
                'responded_at' => now(),
                'decline_reason' => $reason,
                'signer_ip' => $ip,
                'signer_user_agent' => $userAgent ? mb_substr($userAgent, 0, 512) : null,
            ])->save();
            $this->returnToFinal($request);

            return $request;
        });

        $this->notifyRequester($request);

        return $request;
    }

    /** Re-read the request under a row lock so two answers cannot both succeed. */
    private function lockPending(SignatureRequest $request, ?Client $client = null, bool $allowExpired = false): SignatureRequest
    {
        $locked = SignatureRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

        if ($client !== null && (int) $locked->client_id !== (int) $client->id) {
            abort(404);
        }

        if ($locked->status !== SignatureStatus::Pending) {
            throw ValidationException::withMessages(['status' => __('This signature request is already :status.', ['status' => __($locked->status->value)])]);
        }

        if (! $allowExpired && $locked->isExpired()) {
            throw ValidationException::withMessages(['status' => __('This signature request has expired. Please ask your lawyer to send it again.')]);
        }

        return $locked;
    }

    private function returnToFinal(SignatureRequest $request): void
    {
        $document = Document::whereKey($request->document_id)->firstOrFail();

        if ($document->status === DocumentStatus::PendingSignature) {
            $document->forceFill(['status' => DocumentStatus::Final])->save();
        }
    }

    private function notifyRequester(SignatureRequest $request): void
    {
        $request->load(['document', 'client', 'requester']);
        $request->requester?->notify(new SignatureAnswered($request));
    }
}
