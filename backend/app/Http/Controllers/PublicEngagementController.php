<?php

namespace App\Http\Controllers;

use App\Domain\Business\EngagementLetters;
use App\Domain\Business\Models\EngagementLetter;
use App\Domain\Business\Models\Prospect;
use App\Domain\Matters\Models\Firm;
use App\Support\Tenancy\DatabaseTenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The prospect's side of an engagement letter: read it and sign or decline,
 * through the private link they were emailed (no account; the link is the
 * credential, so only its hash is stored).
 */
class PublicEngagementController extends Controller
{
    public function __construct(private readonly EngagementLetters $letters, private readonly TenantContext $tenant, private readonly DatabaseTenancy $database) {}

    public function show(string $token): JsonResponse
    {
        $letter = $this->letter($token);

        return response()->json([
            'firm' => Firm::find($letter->firm_id)?->name,
            'name' => Prospect::withoutGlobalScopes()->find($letter->prospect_id)?->name,
            'content' => $letter->content,
            'status' => $letter->isExpired() && $letter->status === 'sent' ? 'expired' : $letter->status,
            'expires_at' => $letter->expires_at?->toIso8601String(),
        ]);
    }

    public function sign(Request $request, string $token): JsonResponse
    {
        $letter = $this->letter($token);
        $validated = $request->validate([
            'signer_name' => ['required', 'string', 'min:2', 'max:255'],
            'method' => ['required', 'in:drawn,typed'],
            'signature_image' => ['required_if:method,drawn', 'nullable', 'string', 'max:400000', 'regex:/^data:image\/png;base64,[A-Za-z0-9+\/]+=*$/'],
            'consent' => ['accepted'],
        ], ['consent.accepted' => 'Please confirm that you agree to sign electronically.']);
        if ($validated['method'] === 'drawn' && ! $this->isPng((string) $validated['signature_image'])) {
            throw ValidationException::withMessages(['signature_image' => 'The signature could not be read. Please draw it again.']);
        }

        $this->scoped($letter, fn () => $this->letters->sign($letter, trim($validated['signer_name']), $validated['method'], $validated['signature_image'] ?? null, $request->ip(), $request->userAgent()));

        return response()->json(['status' => 'signed']);
    }

    public function decline(Request $request, string $token): JsonResponse
    {
        $letter = $this->letter($token);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $this->scoped($letter, fn () => $this->letters->decline($letter, $validated['reason'] ?? null, $request->ip(), $request->userAgent()));

        return response()->json(['status' => 'declined']);
    }

    private function letter(string $token): EngagementLetter
    {
        $letter = $this->letters->findByToken($token);
        // Signed and declined letters drop their token, so an old link simply stops working.
        abort_if($letter === null, 404);

        return $letter;
    }

    /** Within the letter's firm only, with row-level security in force. */
    private function scoped(EngagementLetter $letter, \Closure $fn): mixed
    {
        return $this->tenant->runAs((int) $letter->firm_id, function () use ($letter, $fn) {
            $this->database->restrictTo((int) $letter->firm_id);
            try {
                return $fn();
            } finally {
                $this->database->bypass();
            }
        });
    }

    private function isPng(string $dataUrl): bool
    {
        $bytes = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);

        return $bytes !== false && str_starts_with($bytes, "\x89PNG\r\n\x1a\n") && @getimagesizefromstring($bytes) !== false;
    }
}
