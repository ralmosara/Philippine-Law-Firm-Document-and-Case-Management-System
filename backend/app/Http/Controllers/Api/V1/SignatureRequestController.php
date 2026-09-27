<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\SignatureRequest;
use App\Domain\Documents\Services\ElectronicSignatures;
use App\Http\Controllers\Controller;
use App\Http\Resources\SignatureRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SignatureRequestController extends Controller
{
    public function __construct(private readonly ElectronicSignatures $signatures) {}

    public function index(Document $document): AnonymousResourceCollection
    {
        return SignatureRequestResource::collection(
            $document->signatureRequests()->with(['client', 'requester', 'version'])->latest('id')->get()
        );
    }

    public function store(Request $request, Document $document): JsonResponse
    {
        Gate::authorize('practice-law');

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:1000'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $signatureRequest = $this->signatures->request(
            $document, $request->user(), $validated['message'] ?? null, $validated['expires_in_days'] ?? 14,
        );

        return (new SignatureRequestResource($signatureRequest->load(['client', 'requester', 'version'])))
            ->response()
            ->setStatusCode(201);
    }

    public function cancel(SignatureRequest $signatureRequest): SignatureRequestResource
    {
        Gate::authorize('practice-law');

        $cancelled = $this->signatures->cancel($signatureRequest);

        return new SignatureRequestResource($cancelled->load(['client', 'requester', 'version']));
    }
}
