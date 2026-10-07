<?php

namespace App\Http\Controllers;

use App\Domain\Compliance\Models\ConflictWaiver;
use App\Domain\Compliance\Services\ConflictWaivers;
use App\Domain\Matters\Models\Firm;
use App\Support\Tenancy\DatabaseTenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** A person reads a conflict-of-interest consent letter and signs or declines it, from the emailed link. */
class PublicConsentController extends Controller
{
    public function __construct(private readonly ConflictWaivers $waivers, private readonly TenantContext $tenant, private readonly DatabaseTenancy $database) {}

    public function show(string $token): JsonResponse
    {
        $w = $this->waiver($token);

        return response()->json([
            'firm' => Firm::find($w->firm_id)?->name,
            'name' => $w->signer_name,
            'content' => $w->content,
            'status' => $w->status === 'sent' && $w->isExpired() ? 'expired' : $w->status,
            'expires_at' => $w->expires_at?->toIso8601String(),
        ]);
    }

    public function sign(Request $request, string $token): JsonResponse
    {
        $w = $this->waiver($token);
        $validated = $request->validate([
            'signer_name' => ['required', 'string', 'min:2', 'max:255'],
            'method' => ['required', 'in:drawn,typed'],
            'signature_image' => ['required_if:method,drawn', 'nullable', 'string', 'max:400000', 'regex:/^data:image\/png;base64,[A-Za-z0-9+\/]+=*$/'],
            'consent' => ['accepted'],
        ], ['consent.accepted' => 'Please confirm that you agree to sign electronically.']);
        if ($validated['method'] === 'drawn') {
            $bytes = base64_decode(substr((string) $validated['signature_image'], strlen('data:image/png;base64,')), true);
            if ($bytes === false || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n") || @getimagesizefromstring($bytes) === false) {
                throw ValidationException::withMessages(['signature_image' => 'The signature could not be read. Please draw it again.']);
            }
        }
        $this->scoped($w, fn () => $this->waivers->sign($w, trim($validated['signer_name']), $validated['method'], $validated['signature_image'] ?? null, $request->ip(), $request->userAgent()));

        return response()->json(['status' => 'signed']);
    }

    public function decline(Request $request, string $token): JsonResponse
    {
        $w = $this->waiver($token);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $this->scoped($w, fn () => $this->waivers->decline($w, $validated['reason'] ?? null, $request->ip(), $request->userAgent()));

        return response()->json(['status' => 'declined']);
    }

    private function waiver(string $token): ConflictWaiver
    {
        $w = $this->waivers->findByToken($token);
        abort_if($w === null, 404);

        return $w;
    }

    private function scoped(ConflictWaiver $w, \Closure $fn): mixed
    {
        return $this->tenant->runAs((int) $w->firm_id, function () use ($w, $fn) {
            $this->database->restrictTo((int) $w->firm_id);
            try {
                return $fn();
            } finally {
                $this->database->bypass();
            }
        });
    }
}
