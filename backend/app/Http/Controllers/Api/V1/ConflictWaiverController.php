<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Compliance\Models\ConflictCheck;
use App\Domain\Compliance\Models\ConflictWaiver;
use App\Domain\Compliance\Services\ConflictWaivers;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Written consents to acting despite a possible conflict, kept with the conflict check. */
class ConflictWaiverController extends Controller
{
    public function __construct(private readonly ConflictWaivers $waivers) {}

    public function index(ConflictCheck $conflictCheck): JsonResponse
    {
        Gate::authorize('practice-law');

        return response()->json(ConflictWaiver::query()->where('conflict_check_id', $conflictCheck->id)->latest('id')->get()->map(fn ($w) => self::payload($w)));
    }

    /** A first draft of the letter from the facts and the lawyer's explanation, to edit before sending. */
    public function draft(Request $request, ConflictCheck $conflictCheck): JsonResponse
    {
        Gate::authorize('practice-law');
        $validated = $request->validate([
            'signer_name' => ['required', 'string', 'max:255'],
            'situation' => ['required', 'string', 'max:5000'],
            'explanation' => ['required', 'string', 'max:5000'],
        ], ['situation.required' => 'Describe the facts that create the possible conflict.', 'explanation.required' => 'Explain what it means for them and how the firm will handle it.']);

        return response()->json(['content' => $this->waivers->compose($conflictCheck, $validated['signer_name'], $validated['situation'], $validated['explanation'], $request->user())]);
    }

    public function store(Request $request, ConflictCheck $conflictCheck): JsonResponse
    {
        Gate::authorize('practice-law');
        $validated = $request->validate([
            'signer_name' => ['required', 'string', 'max:255'],
            'signer_email' => ['required', 'email', 'max:255'],
            'content' => ['required', 'string', 'max:100000'],
        ]);

        return response()->json(self::payload($this->waivers->send($conflictCheck, $validated['signer_name'], $validated['signer_email'], $validated['content'], $request->user())), 201);
    }

    public function cancel(ConflictWaiver $waiver): JsonResponse
    {
        Gate::authorize('practice-law');

        return response()->json(self::payload($this->waivers->cancel($waiver)));
    }

    public static function payload(ConflictWaiver $w): array
    {
        return [
            'id' => $w->id,
            'signer_name' => $w->signer_name,
            'signer_email' => $w->signer_email,
            'status' => $w->status === 'sent' && $w->isExpired() ? 'expired' : $w->status,
            'content' => $w->content,
            'sent_at' => $w->sent_at?->toIso8601String(),
            'expires_at' => $w->expires_at?->toIso8601String(),
            'responded_at' => $w->responded_at?->toIso8601String(),
            'signed_name' => $w->signed_name,
            'decline_reason' => $w->decline_reason,
        ];
    }
}
