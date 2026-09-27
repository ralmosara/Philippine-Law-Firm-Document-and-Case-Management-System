<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Privacy\Models\DataSubjectRequest;
use App\Domain\Privacy\Models\PrivacyIncident;
use App\Domain\Privacy\PrivacyNotice;
use App\Domain\Privacy\PrivacyService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Data Privacy Act compliance for the firm's DPO and managing partners:
 * the notice, data subject requests, retention and disposal, and breaches.
 */
class PrivacyController extends Controller
{
    public function __construct(private readonly PrivacyService $privacy) {}

    /** Counts for the privacy page header and the dashboard. */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');
        $firm = $this->firm($request);

        return response()->json([
            'open_requests' => DataSubjectRequest::where('status', DataSubjectRequest::OPEN)->count(),
            'overdue_requests' => DataSubjectRequest::where('status', DataSubjectRequest::OPEN)->whereDate('due_on', '<', today())->count(),
            'incidents_awaiting_npc' => PrivacyIncident::where('notifiable', true)->whereNull('npc_notified_at')->count(),
            'due_for_disposal' => $this->privacy->dueForDisposal($firm)->count(),
            'clients_without_consent' => Client::where('portal_enabled', true)->whereNull('anonymized_at')
                ->where(fn ($q) => $q->whereNull('privacy_notice_version')->orWhere('privacy_notice_version', '<', $firm->privacy_notice_version))->count(),
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');

        return response()->json($this->settingsPayload($this->firm($request)));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');
        $validated = $request->validate([
            'dpo_name' => ['nullable', 'string', 'max:255'],
            'dpo_email' => ['nullable', 'email', 'max:255'],
            'privacy_notice' => ['nullable', 'string', 'max:20000'],
            'retention_years' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $firm = $this->firm($request);
        $before = PrivacyNotice::text($firm);
        $firm->fill($validated);

        // A changed notice is a new version: portal clients are asked to accept it again.
        if (PrivacyNotice::text($firm) !== $before) {
            $firm->forceFill(['privacy_notice_version' => $firm->privacy_notice_version + 1, 'privacy_notice_updated_at' => now()]);
        }
        $firm->save();

        return response()->json($this->settingsPayload($firm));
    }

    public function requests(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');
        $request->validate(['status' => ['nullable', Rule::in([DataSubjectRequest::OPEN, DataSubjectRequest::COMPLETED, DataSubjectRequest::DENIED])]]);

        return response()->json(DataSubjectRequest::query()
            ->with(['client:id,name,anonymized_at', 'handler:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->orderBy('due_on')->latest('id')
            ->limit(200)->get()
            ->map(fn (DataSubjectRequest $r) => $this->requestPayload($r)));
    }

    public function storeRequest(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');
        $validated = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'requester_name' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'requester_email' => ['nullable', 'email', 'max:255'],
            'type' => ['required', Rule::in(array_keys(DataSubjectRequest::TYPES))],
            'details' => ['nullable', 'string', 'max:5000'],
        ]);
        $client = isset($validated['client_id']) ? Client::findOrFail($validated['client_id']) : null;

        $dsr = $this->privacy->receiveRequest($this->firm($request), $validated, $client, 'staff');

        return response()->json($this->requestPayload($dsr->load('client:id,name,anonymized_at')), 201);
    }

    public function resolveRequest(Request $request, DataSubjectRequest $dataSubjectRequest): JsonResponse
    {
        Gate::authorize('manage-firm');
        $validated = $request->validate([
            'status' => ['required', Rule::in([DataSubjectRequest::COMPLETED, DataSubjectRequest::DENIED])],
            'resolution' => ['required', 'string', 'max:5000'],
        ]);

        $dsr = $this->privacy->resolve($dataSubjectRequest, $validated['status'], $validated['resolution'], $request->user());

        return response()->json($this->requestPayload($dsr->load(['client:id,name,anonymized_at', 'handler:id,name'])));
    }

    /** Everything held about a client, as JSON: for access and portability requests. */
    public function export(Client $client): StreamedResponse
    {
        Gate::authorize('manage-firm');
        $data = $this->privacy->export($client);

        return response()->streamDownload(
            fn () => print (json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'personal-data-'.Str::slug($client->name).'-'.now()->format('Y-m-d').'.json',
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    public function anonymize(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('manage-firm');
        $request->validate(['confirm' => ['required', 'accepted']]);

        $client = $this->privacy->anonymize($client, $request->user());

        return response()->json(['id' => $client->id, 'name' => $client->name, 'anonymized_at' => $client->anonymized_at?->toIso8601String()]);
    }

    public function retention(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');
        $firm = $this->firm($request);

        return response()->json([
            'retention_years' => $firm->retention_years,
            'matters' => $this->privacy->dueForDisposal($firm)->map(fn (Matter $m) => [
                'id' => $m->id,
                'reference' => $m->reference,
                'title' => $m->title,
                'client' => $m->client?->name,
                'closed_at' => $m->closed_at?->toDateString(),
                'files' => (int) $m->files_count,
            ]),
        ]);
    }

    public function dispose(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('manage-firm');
        $request->validate(['confirm' => ['required', 'accepted']]);

        return response()->json($this->privacy->dispose($matter, $request->user()));
    }

    public function incidents(): JsonResponse
    {
        Gate::authorize('manage-firm');

        return response()->json(PrivacyIncident::with('reporter:id,name')->latest('discovered_at')->limit(200)->get()->map(fn (PrivacyIncident $i) => $this->incidentPayload($i)));
    }

    public function storeIncident(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');
        $incident = PrivacyIncident::create([
            ...$request->validate($this->incidentRules()),
            'firm_id' => $request->user()->firm_id,
            'reported_by' => $request->user()->id,
        ]);

        return response()->json($this->incidentPayload($incident->load('reporter:id,name')), 201);
    }

    public function updateIncident(Request $request, PrivacyIncident $privacyIncident): JsonResponse
    {
        Gate::authorize('manage-firm');
        $privacyIncident->update($request->validate([
            ...array_map(fn ($rules) => ['sometimes', ...$rules], $this->incidentRules()),
            'npc_notified_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
            'subjects_notified_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
            'actions_taken' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(PrivacyIncident::STATUSES)],
        ]));

        return response()->json($this->incidentPayload($privacyIncident->load('reporter:id,name')));
    }

    private function incidentRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'discovered_at' => ['required', 'date', 'before_or_equal:now'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'affected_count' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'data_involved' => ['nullable', 'string', 'max:1000'],
            'sensitive' => ['boolean'],
            'notifiable' => ['boolean'],
        ];
    }

    private function firm(Request $request): Firm
    {
        return Firm::findOrFail($request->user()->firm_id);
    }

    private function settingsPayload(Firm $firm): array
    {
        return [
            'dpo_name' => $firm->dpo_name,
            'dpo_email' => $firm->dpo_email,
            'privacy_notice' => $firm->privacy_notice,
            'default_notice' => PrivacyNotice::default($firm),
            'notice_text' => PrivacyNotice::text($firm),
            'privacy_notice_version' => $firm->privacy_notice_version,
            'privacy_notice_updated_at' => $firm->privacy_notice_updated_at?->toIso8601String(),
            'retention_years' => $firm->retention_years,
        ];
    }

    private function requestPayload(DataSubjectRequest $r): array
    {
        return [
            'id' => $r->id,
            'type' => $r->type,
            'type_label' => DataSubjectRequest::TYPES[$r->type] ?? $r->type,
            'requester_name' => $r->requester_name,
            'requester_email' => $r->requester_email,
            'client' => $r->client ? ['id' => $r->client->id, 'name' => $r->client->name, 'anonymized' => $r->client->anonymized_at !== null] : null,
            'details' => $r->details,
            'source' => $r->source,
            'status' => $r->status,
            'due_on' => $r->due_on->toDateString(),
            'is_overdue' => $r->isOverdue(),
            'resolution' => $r->resolution,
            'handled_by' => $r->relationLoaded('handler') ? $r->handler?->name : null,
            'resolved_at' => $r->resolved_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    private function incidentPayload(PrivacyIncident $i): array
    {
        return [
            ...$i->only(['id', 'title', 'description', 'affected_count', 'data_involved', 'sensitive', 'notifiable', 'actions_taken', 'status']),
            'discovered_at' => $i->discovered_at->toIso8601String(),
            'occurred_at' => $i->occurred_at?->toIso8601String(),
            'npc_notified_at' => $i->npc_notified_at?->toIso8601String(),
            'subjects_notified_at' => $i->subjects_notified_at?->toIso8601String(),
            'notify_by' => $i->notifyBy()->toIso8601String(),
            'npc_notification_pending' => $i->npcNotificationPending(),
            'reported_by' => $i->reporter?->name,
        ];
    }
}
