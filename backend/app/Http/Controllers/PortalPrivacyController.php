<?php

namespace App\Http\Controllers;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Privacy\Models\DataSubjectRequest;
use App\Domain\Privacy\PrivacyNotice;
use App\Domain\Privacy\PrivacyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A client's side of the Data Privacy Act: the notice, consent, and their requests. */
class PortalPrivacyController extends Controller
{
    public function __construct(private readonly PrivacyService $privacy) {}

    public function show(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $firm = Firm::findOrFail($client->firm_id);

        return response()->json([
            'notice' => PrivacyNotice::text($firm),
            'version' => $firm->privacy_notice_version,
            'accepted_version' => $client->privacy_notice_version,
            'accepted_at' => $client->privacy_accepted_at?->toIso8601String(),
            'needs_acceptance' => $client->privacy_notice_version === null || $client->privacy_notice_version < $firm->privacy_notice_version,
            'dpo' => ['name' => $firm->dpo_name, 'email' => $firm->dpo_email ?: $firm->email],
            'request_types' => collect(DataSubjectRequest::TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'requests' => DataSubjectRequest::where('client_id', $client->id)->latest('id')->get()->map(fn (DataSubjectRequest $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'type_label' => DataSubjectRequest::TYPES[$r->type] ?? $r->type,
                'details' => $r->details,
                'status' => $r->status,
                'due_on' => $r->due_on->toDateString(),
                'resolution' => $r->resolution,
                'created_at' => $r->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function accept(Request $request): JsonResponse
    {
        $this->privacy->acceptNotice($this->client($request));

        return $this->show($request);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(DataSubjectRequest::TYPES))],
            'details' => ['required_if:type,correction,objection', 'nullable', 'string', 'max:5000'],
        ], ['details.required_if' => 'Tell us what should change, or which use you object to.']);

        $client = $this->client($request);
        $this->privacy->receiveRequest(Firm::findOrFail($client->firm_id), $validated, $client, 'portal');

        return $this->show($request)->setStatusCode(201);
    }

    private function client(Request $request): Client
    {
        return $request->user('client');
    }
}
