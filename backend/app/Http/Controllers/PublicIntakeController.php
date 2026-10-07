<?php

namespace App\Http\Controllers;

use App\Domain\Intake\IntakeQuestions;
use App\Domain\Intake\Services\IntakeService;
use App\Domain\Matters\Models\Firm;
use App\Domain\Privacy\PrivacyNotice;
use App\Http\Controllers\Api\V1\LookupController;
use App\Support\Localization\PortalLocale;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The firm's public consultation-request form (/consult/{slug} in the app).
 * No sign-in; rate-limited per IP address, with a honeypot field against bots.
 */
class PublicIntakeController extends Controller
{
    private const RECEIVED = 'Thank you. Your request was received; we will email you to confirm a consultation schedule.';

    /** Also the label shown for each case type (translated where a Filipino name exists). */
    private static function caseTypes(): array
    {
        return array_map(fn (string $type) => ['value' => $type, 'label' => __($type)], LookupController::CASE_TYPES);
    }

    public function show(string $slug): JsonResponse
    {
        $firm = $this->firm($slug);

        return response()->json([
            'firm' => $firm->only(['name', 'address', 'phone', 'email']),
            'message' => $firm->intake_message,
            'case_types' => self::caseTypes(),
            // The firm's own questions for each type of case, shown when that type is chosen.
            'questions' => $firm->intake_questions ?? (object) [],
            'privacy_notice' => PrivacyNotice::text($firm, PortalLocale::normalize(app()->getLocale())),
        ]);
    }

    public function submit(Request $request, string $slug, IntakeService $intake): JsonResponse
    {
        $firm = $this->firm($slug);

        // Bots fill every field. Answer as usual so they learn nothing, but keep nothing.
        if ($request->filled('website')) {
            return response()->json(['message' => __(self::RECEIVED)], 201);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
            'client_type' => ['required', Rule::in(['individual', 'corporate'])],
            'case_type' => ['required', Rule::in(LookupController::CASE_TYPES)],
            'description' => ['required', 'string', 'min:20', 'max:5000'],
            'opposing_parties' => ['array', 'max:10'],
            'opposing_parties.*' => ['nullable', 'string', 'min:2', 'max:255'],
            'preferred_times' => ['array', 'max:3'],
            'preferred_times.*' => ['date', 'after:now', 'before:+6 months'],
            // When the problem arose, as the applicant remembers it: lets a lawyer see
            // early whether the claim is about to prescribe.
            'incident_on' => ['nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:today'],
            'consent' => ['accepted'],
            ...app(IntakeQuestions::class)->rules($firm, (string) $request->input('case_type')),
        ], [
            'consent.accepted' => __('Please agree to the processing of your information so we can respond.'),
            'description.min' => __('Please tell us a little more about your concern (at least 20 characters).'),
            'incident_on.before_or_equal' => __('The date cannot be in the future.'),
        ], app(IntakeQuestions::class)->attributes($firm, (string) $request->input('case_type')));
        $validated['answers'] = app(IntakeQuestions::class)->answers($firm, $validated['case_type'], $validated['answers'] ?? []);

        // The form's times are Philippine time as typed; store them with the offset.
        $validated['preferred_times'] = array_map(
            fn (string $time) => CarbonImmutable::parse($time, 'Asia/Manila')->toIso8601String(),
            $validated['preferred_times'] ?? [],
        );

        $intake->submit($firm, $validated, $request->ip());

        return response()->json(['message' => __(self::RECEIVED)], 201);
    }

    private function firm(string $slug): Firm
    {
        return Firm::where('slug', $slug)->where('intake_enabled', true)->firstOrFail();
    }
}
