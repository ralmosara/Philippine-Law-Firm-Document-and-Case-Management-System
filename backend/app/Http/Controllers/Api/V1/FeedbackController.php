<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Feedback\ClientFeedback;
use App\Domain\Feedback\MatterFeedback;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/** Client feedback on closed matters: the answers, a summary by lawyer and practice area, and follow-ups. */
class FeedbackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('practice-law');
        $validated = $request->validate(['months' => ['nullable', 'integer', 'min:1', 'max:60'], 'show' => ['nullable', 'in:all,low,unanswered']]);
        $since = now()->subMonths((int) ($validated['months'] ?? 12));

        $all = MatterFeedback::query()
            ->where('requested_at', '>=', $since)
            ->with('matter:id,reference,title,case_type,responsible_lawyer_id', 'matter.responsibleLawyer:id,name', 'client:id,name', 'followedUpBy:id,name')
            ->latest('requested_at')
            ->get();

        $answered = $all->whereNotNull('responded_at');
        $rows = match ($validated['show'] ?? 'all') {
            'low' => $answered->filter->isLow(),
            'unanswered' => $all->whereNull('responded_at'),
            default => $all,
        };

        return response()->json([
            'summary' => $this->summarize($all),
            'by_lawyer' => $all->groupBy(fn ($f) => $f->matter->responsibleLawyer?->name ?? 'No lawyer assigned')->map(fn ($g, $name) => ['name' => $name, ...$this->summarize($g)])->sortByDesc('answered')->values(),
            'by_practice_area' => $all->groupBy(fn ($f) => $f->matter->case_type ?: 'Other')->map(fn ($g, $name) => ['name' => $name, ...$this->summarize($g)])->sortByDesc('answered')->values(),
            'low_open' => $answered->filter(fn ($f) => $f->isLow() && $f->followed_up_at === null)->count(),
            'data' => $rows->take(200)->values()->map(fn (MatterFeedback $f) => [
                'id' => $f->id,
                'matter' => ['id' => $f->matter->id, 'reference' => $f->matter->reference, 'title' => $f->matter->title, 'case_type' => $f->matter->case_type, 'lawyer' => $f->matter->responsibleLawyer?->name],
                'client' => $f->client?->name,
                'requested_at' => $f->requested_at->toIso8601String(),
                'rating' => $f->rating,
                'comment' => $f->comment,
                'responded_at' => $f->responded_at?->toIso8601String(),
                'is_low' => $f->isLow(),
                'followed_up_at' => $f->followed_up_at?->toIso8601String(),
                'followed_up_by' => $f->followedUpBy?->name,
                'follow_up_note' => $f->follow_up_note,
            ]),
        ]);
    }

    public function followUp(Request $request, MatterFeedback $feedback, ClientFeedback $service): JsonResponse
    {
        Gate::authorize('practice-law');
        $validated = $request->validate(['note' => ['required', 'string', 'min:5', 'max:2000']], ['note.min' => 'Say briefly what was done.']);
        $service->followUp($feedback, $validated['note'], $request->user());

        return response()->json(['followed_up_at' => $feedback->followed_up_at->toIso8601String()]);
    }

    /** @param Collection<int, MatterFeedback> $items */
    private function summarize(Collection $items): array
    {
        $answered = $items->whereNotNull('responded_at');

        return [
            'requested' => $items->count(),
            'answered' => $answered->count(),
            'response_rate' => $items->count() ? (int) round($answered->count() * 100 / $items->count()) : null,
            'average' => $answered->count() ? round($answered->avg('rating'), 1) : null,
            'low' => $answered->filter->isLow()->count(),
        ];
    }
}
