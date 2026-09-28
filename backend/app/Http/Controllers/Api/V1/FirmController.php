<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Pleadings\DocxWriter;
use App\Domain\Matters\Models\Firm;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The signed-in user's own firm: profile and firm-wide security settings. */
class FirmController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($this->firm($request)));
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'tin' => ['sometimes', 'nullable', 'regex:/^\d{3}-?\d{3}-?\d{3}(-?\d{3,5})?$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'vat_registered' => ['sometimes', 'boolean'],
            'require_two_factor' => ['sometimes', 'boolean'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('firms', 'slug')->ignore($request->user()->firm_id)],
            'intake_enabled' => ['sometimes', 'boolean'],
            'intake_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'ai_enabled' => ['sometimes', 'boolean'],
            // Creditable withholding on professional fees: 5% (0500), 10% (1000) or 15% (1500) are usual.
            'default_withholding_bps' => ['sometimes', 'integer', 'min:0', 'max:3000'],
            'payment_reminders_enabled' => ['sometimes', 'boolean'],
            'pleading_paper' => ['sometimes', Rule::in(array_keys(DocxWriter::PAPERS))],
            'pleading_font' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Za-z0-9 ]+$/'],
            'pleading_font_size' => ['sometimes', 'integer', 'min:10', 'max:16'],
            'taxpayer_type' => ['sometimes', Rule::in(['individual', 'juridical'])],
            'withholding_atc' => ['sometimes', 'string', 'max:8', 'regex:/^[A-Z]{2}[0-9]{3}$/'],
            'has_employees' => ['sometimes', 'boolean'],
        ], ['slug.regex' => 'Use lowercase letters, numbers and single hyphens, e.g. santos-reyes-law.']);

        $firm = $this->firm($request);
        $user = $request->user();

        if (($validated['intake_enabled'] ?? $firm->intake_enabled) && blank($validated['slug'] ?? $firm->slug)) {
            throw ValidationException::withMessages(['slug' => 'Choose the web address for your intake page first.']);
        }

        // Whoever turns the rule on must already comply, so an administrator
        // cannot lock themselves out mid-change.
        if (($validated['require_two_factor'] ?? false) && ! $firm->require_two_factor && ! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'require_two_factor' => 'Turn on two-step verification for your own account (Profile) first.',
            ]);
        }

        $before = $firm->only(array_keys($validated));
        $firm->update($validated);

        if ($firm->wasChanged()) {
            AuditLog::record('firm_settings_updated', $firm->id, $user, null, [
                'before' => $before,
                'after' => $firm->only(array_keys($firm->getChanges())),
            ]);
        }

        return response()->json($this->payload($firm));
    }

    private function firm(Request $request): Firm
    {
        /** @var User $user */
        $user = $request->user();

        return Firm::findOrFail($user->firm_id);
    }

    private function payload(Firm $firm): array
    {
        return [
            ...$firm->only(['id', 'name', 'tin', 'address', 'email', 'phone', 'vat_registered', 'require_two_factor', 'slug', 'intake_enabled', 'intake_message', 'ai_enabled', 'default_withholding_bps', 'payment_reminders_enabled', 'pleading_paper', 'pleading_font', 'pleading_font_size', 'taxpayer_type', 'withholding_atc', 'has_employees']),
            'ai_configured' => filled(config('services.anthropic.api_key')),
            'users_without_two_factor' => User::where('firm_id', $firm->id)
                ->where('is_active', true)
                ->whereNull('two_factor_confirmed_at')
                ->count(),
        ];
    }
}
