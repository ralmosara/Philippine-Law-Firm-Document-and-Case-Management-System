<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\EngagementLetters;
use App\Domain\Business\Models\EngagementLetter;
use App\Domain\Business\Models\Prospect;
use App\Domain\Matters\Enums\FeeArrangement;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;

/** Drafting and sending a prospect's engagement letter. Lawyers only: it sets the fees. */
class EngagementLetterController extends Controller
{
    public function __construct(private readonly EngagementLetters $letters) {}

    public function index(Prospect $prospect): JsonResponse
    {
        Gate::authorize('practice-law');

        return response()->json(EngagementLetter::query()->where('prospect_id', $prospect->id)->latest('id')->get()->map(fn ($l) => self::payload($l)));
    }

    public function store(Request $request, Prospect $prospect): JsonResponse
    {
        Gate::authorize('practice-law');
        $validated = $request->validate([
            'fee_arrangement' => ['required', new Enum(FeeArrangement::class)],
            'fixed_fee_cents' => ['nullable', 'integer', 'min:0', 'max:2000000000', 'required_if:fee_arrangement,flat,retainer'],
            'acceptance_fee_cents' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'appearance_fee_cents' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'contingency_basis_points' => ['nullable', 'integer', 'min:1', 'max:10000', 'required_if:fee_arrangement,contingency'],
            'scope' => ['required', 'string', 'max:5000'],
            'content' => ['nullable', 'string', 'max:100000'],
        ], [
            'fixed_fee_cents.required_if' => 'Enter the fee.',
            'contingency_basis_points.required_if' => 'Enter the share of the recovery.',
        ]);

        return response()->json(self::payload($this->letters->draft($prospect, $validated, $request->user())), 201);
    }

    public function send(Request $request, Prospect $prospect, EngagementLetter $letter): JsonResponse
    {
        Gate::authorize('practice-law');
        abort_unless((int) $letter->prospect_id === (int) $prospect->id, 404);

        return response()->json(self::payload($this->letters->send($letter, $request->user())));
    }

    public function cancel(Prospect $prospect, EngagementLetter $letter): JsonResponse
    {
        Gate::authorize('practice-law');
        abort_unless((int) $letter->prospect_id === (int) $prospect->id, 404);

        return response()->json(self::payload($this->letters->cancel($letter)));
    }

    public static function payload(EngagementLetter $l): array
    {
        return [
            'id' => $l->id,
            'status' => $l->status,
            'fee_arrangement' => $l->fee_arrangement->value,
            'fixed_fee_cents' => $l->fixed_fee_cents,
            'acceptance_fee_cents' => $l->acceptance_fee_cents,
            'appearance_fee_cents' => $l->appearance_fee_cents,
            'contingency_basis_points' => $l->contingency_basis_points,
            'scope' => $l->scope,
            'content' => $l->content,
            'sent_at' => $l->sent_at?->toIso8601String(),
            'expires_at' => $l->expires_at?->toIso8601String(),
            'responded_at' => $l->responded_at?->toIso8601String(),
            'signer_name' => $l->signer_name,
            'decline_reason' => $l->decline_reason,
            'matter_id' => $l->matter_id,
            'document_id' => $l->document_id,
        ];
    }
}
