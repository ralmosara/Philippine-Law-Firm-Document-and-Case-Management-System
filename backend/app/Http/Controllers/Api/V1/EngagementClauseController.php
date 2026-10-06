<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\EngagementLetters;
use App\Domain\Matters\Models\Firm;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The firm's own wording for the standard clauses in new engagement letters. */
class EngagementClauseController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('practice-law');

        return response()->json($this->payload(Firm::findOrFail($request->user()->firm_id)));
    }

    /** A blank clause goes back to the standard text. */
    public function update(Request $request): JsonResponse
    {
        Gate::authorize('manage-firm');
        $rules = [];
        foreach (array_keys(EngagementLetters::CLAUSES) as $key) {
            $rules["clauses.{$key}"] = ['nullable', 'string', 'max:5000'];
        }
        $validated = $request->validate(['clauses' => ['required', 'array'], ...$rules]);

        $firm = Firm::findOrFail($request->user()->firm_id);
        $custom = collect($validated['clauses'])
            ->only(array_keys(EngagementLetters::CLAUSES))
            ->map(fn ($text) => trim((string) $text))
            ->filter(fn ($text, $key) => $text !== '' && $text !== EngagementLetters::CLAUSES[$key]['text'])
            ->all();
        $before = $firm->engagement_clauses;
        $firm->forceFill(['engagement_clauses' => $custom ?: null])->save();
        AuditLog::record('engagement_clauses_updated', $firm->id, $request->user(), null, ['before' => $before, 'after' => $custom]);

        return response()->json($this->payload($firm));
    }

    private function payload(Firm $firm): array
    {
        $custom = $firm->engagement_clauses ?? [];

        return ['clauses' => collect(EngagementLetters::CLAUSES)->map(fn ($c, $key) => [
            'key' => $key,
            'title' => $c['title'],
            'standard' => $c['text'],
            'text' => $custom[$key] ?? $c['text'],
            'customised' => isset($custom[$key]),
        ])->values()];
    }
}
