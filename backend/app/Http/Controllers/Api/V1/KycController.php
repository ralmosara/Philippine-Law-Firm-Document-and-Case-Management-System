<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Compliance\Models\AmlReview;
use App\Domain\Compliance\Models\BeneficialOwner;
use App\Domain\Compliance\Models\ClientIdentification;
use App\Domain\Compliance\Services\AmlMonitor;
use App\Domain\Compliance\Services\KnowYourClient;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Client identification, beneficial owners and risk rating; and the AML review queue. */
class KycController extends Controller
{
    public function __construct(private readonly KnowYourClient $kyc, private readonly AmlMonitor $aml) {}

    public function show(Client $client): JsonResponse
    {
        Gate::authorize('practice-law');

        return response()->json($this->payload($client));
    }

    public function storeIdentification(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('practice-law');
        $validated = $request->validate([
            'id_type' => ['required', Rule::in(ClientIdentification::TYPES)],
            'id_number' => ['required', 'string', 'max:80'],
            'issued_on' => ['nullable', 'date', 'before_or_equal:today'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'notes' => ['nullable', 'string', 'max:500'],
            'scan' => ['nullable', File::types(['jpg', 'jpeg', 'png', 'pdf'])->max(10 * 1024)],
        ]);

        $this->kyc->addIdentification($client, $validated, $request->file('scan'), $request->user());

        return response()->json($this->payload($client), 201);
    }

    public function destroyIdentification(Client $client, ClientIdentification $identification): JsonResponse
    {
        Gate::authorize('practice-law');
        abort_unless((int) $identification->client_id === (int) $client->id, 404);
        $this->kyc->removeIdentification($identification);

        return response()->json($this->payload($client));
    }

    /** The scan, for staff only; each viewing is logged (it is sensitive personal information). */
    public function scan(Request $request, Client $client, ClientIdentification $identification): StreamedResponse
    {
        Gate::authorize('practice-law');
        abort_unless((int) $identification->client_id === (int) $client->id && $identification->path, 404);
        AuditLog::record('kyc_id_viewed', $client->firm_id, $request->user(), $client, ['identification' => $identification->id]);

        return Storage::disk('local')->download($identification->path, $identification->original_name ?? 'identification', ['Content-Type' => $identification->mime_type ?? 'application/octet-stream']);
    }

    public function storeOwner(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('practice-law');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ownership_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'position' => ['nullable', 'string', 'max:120'],
            'nationality' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        BeneficialOwner::create([...$validated, 'firm_id' => $client->firm_id, 'client_id' => $client->id]);

        return response()->json($this->payload($client), 201);
    }

    public function destroyOwner(Client $client, BeneficialOwner $owner): JsonResponse
    {
        Gate::authorize('practice-law');
        abort_unless((int) $owner->client_id === (int) $client->id, 404);
        $owner->delete();

        return response()->json($this->payload($client));
    }

    public function review(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('practice-law');
        $validated = $request->validate([
            'risk' => ['required', Rule::in(['low', 'normal', 'high'])],
            'is_pep' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000', Rule::requiredIf(fn () => $request->input('risk') === 'high' || $request->boolean('is_pep'))],
        ], ['notes.required' => 'Record why, and what enhanced checks were done, for a high-risk client or a politically exposed person.']);

        $this->kyc->review($client, $validated, $request->user());

        return response()->json($this->payload($client->fresh()));
    }

    public function reviews(Request $request): JsonResponse
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate(['status' => ['nullable', Rule::in(['pending', 'not_reportable', 'reported'])]]);
        $firm = Firm::findOrFail($request->user()->firm_id);

        return response()->json([
            'threshold_cents' => $firm->aml_threshold_cents,
            'threshold_confirmed_at' => $firm->aml_threshold_confirmed_at?->toIso8601String(),
            'data' => AmlReview::query()->where('status', $validated['status'] ?? 'pending')->with(['client:id,name', 'decider:id,name'])->latest('day')->limit(200)->get()->map(fn (AmlReview $r) => [
                'id' => $r->id,
                'client' => $r->client ? ['id' => $r->client->id, 'name' => $r->client->name] : null,
                'day' => $r->day->toDateString(),
                'amount_cents' => $r->amount_cents,
                'deposits' => count($r->trust_transaction_ids),
                'status' => $r->status,
                'notes' => $r->notes,
                'report_reference' => $r->report_reference,
                'decided_by' => $r->decider?->name,
                'decided_at' => $r->decided_at?->toIso8601String(),
            ]),
        ]);
    }

    public function decide(Request $request, AmlReview $review): JsonResponse
    {
        Gate::authorize('manage-finances');
        $validated = $request->validate([
            'status' => ['required', Rule::in(['not_reportable', 'reported'])],
            'notes' => ['required', 'string', 'max:5000'],
            'report_reference' => ['nullable', 'string', 'max:100'],
        ]);
        $this->aml->decide($review, $validated['status'], $validated['notes'], $validated['report_reference'] ?? null, $request->user());

        return response()->json(['status' => $review->status]);
    }

    private function payload(Client $client): array
    {
        return [
            'risk' => $client->kyc_risk,
            'is_pep' => (bool) $client->is_pep,
            'notes' => $client->kyc_notes,
            'reviewed_at' => $client->kyc_reviewed_at?->toIso8601String(),
            'reviewed_by' => $client->kyc_reviewed_by ? User::find($client->kyc_reviewed_by)?->name : null,
            'problems' => $this->kyc->problems($client),
            'identifications' => ClientIdentification::query()->where('client_id', $client->id)->with('verifier:id,name')->latest('id')->get()->map(fn (ClientIdentification $i) => [
                'id' => $i->id,
                'id_type' => $i->id_type,
                'id_number' => $i->id_number,
                'issued_on' => $i->issued_on?->toDateString(),
                'expires_on' => $i->expires_on?->toDateString(),
                'expired' => $i->isExpired(),
                'has_scan' => $i->path !== null,
                'notes' => $i->notes,
                'verified_by' => $i->verifier?->name,
                'verified_at' => $i->verified_at?->toIso8601String(),
            ]),
            'owners' => BeneficialOwner::query()->where('client_id', $client->id)->orderByDesc('ownership_bps')->get(['id', 'name', 'ownership_bps', 'position', 'nationality', 'notes']),
            'id_types' => ClientIdentification::TYPES,
        ];
    }
}
