<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Intake\IntakeQuestions;
use App\Domain\Matters\Models\Firm;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The firm's own questions on the public consultation form, per type of case. */
class IntakeQuestionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');

        return response()->json(['case_types' => LookupController::CASE_TYPES, 'types' => IntakeQuestions::TYPES, 'questions' => Firm::findOrFail($request->user()->firm_id)->intake_questions ?? (object) []]);
    }

    public function update(Request $request, IntakeQuestions $questions): JsonResponse
    {
        Gate::authorize('manage-firm');
        $validated = $request->validate([
            'questions' => ['present', 'array'],
            'questions.*' => ['array', 'max:'.IntakeQuestions::MAX_PER_TYPE],
            'questions.*.*.label' => ['required', 'string', 'max:200'],
            'questions.*.*.type' => ['required', Rule::in(IntakeQuestions::TYPES)],
            'questions.*.*.required' => ['boolean'],
            'questions.*.*.hint' => ['nullable', 'string', 'max:200'],
        ]);
        $unknown = array_diff(array_keys($validated['questions']), LookupController::CASE_TYPES);
        abort_if($unknown !== [], 422, 'Unknown case type: '.implode(', ', $unknown));

        $firm = Firm::findOrFail($request->user()->firm_id);
        $clean = $questions->normalise($validated['questions']);
        $firm->forceFill(['intake_questions' => $clean ?: null])->save();
        AuditLog::record('intake_questions_updated', $firm->id, $request->user(), null, ['case_types' => array_keys($clean)]);

        return response()->json(['questions' => $clean ?: (object) []]);
    }
}
