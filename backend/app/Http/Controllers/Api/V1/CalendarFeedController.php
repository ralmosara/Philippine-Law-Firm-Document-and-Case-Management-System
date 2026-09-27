<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Deadlines\Models\CalendarFeed;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Calendar\ICalendar;
use App\Support\Tenancy\DatabaseTenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Private calendar subscriptions: hearings, filing deadlines and tasks in
 * Google Calendar, Outlook or a phone, refreshed by the calendar app.
 */
class CalendarFeedController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $feed = CalendarFeed::where('user_id', $request->user()->id)->first();

        return response()->json(['feed' => $feed ? $this->summary($feed) : null]);
    }

    /** Create the feed, or replace it (the old URL stops working). The URL is shown only now. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', Rule::in(['mine', 'firm'])],
            'show_details' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $token = Str::random(40);

        CalendarFeed::where('user_id', $user->id)->delete();
        $feed = CalendarFeed::create([...$validated, 'firm_id' => $user->firm_id, 'user_id' => $user->id, 'token_hash' => CalendarFeed::hashToken($token)]);
        AuditLog::record('calendar_feed_created', $user->firm_id, $user, $user, ['scope' => $feed->scope, 'show_details' => $feed->show_details]);

        return response()->json([
            'feed' => $this->summary($feed),
            'url' => url("/api/calendar/{$token}.ics"),
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        CalendarFeed::where('user_id', $request->user()->id)->delete();
        AuditLog::record('calendar_feed_revoked', $request->user()->firm_id, $request->user(), $request->user());

        return response()->json(null, 204);
    }

    /** The public feed. The unguessable token in the URL is the credential. */
    public function feed(string $token, TenantContext $tenant, DatabaseTenancy $database): Response
    {
        $feed = CalendarFeed::withoutGlobalScopes()->where('token_hash', CalendarFeed::hashToken($token))->first();
        $user = $feed ? User::withoutGlobalScopes()->find($feed->user_id) : null;

        abort_if($feed === null || $user === null || ! $user->is_active, 404);

        if ($feed->last_accessed_at === null || $feed->last_accessed_at->lt(now()->subMinutes(10))) {
            $feed->forceFill(['last_accessed_at' => now()])->saveQuietly();
        }

        $ics = $tenant->runAs((int) $feed->firm_id, function () use ($feed, $user, $database) {
            $database->restrictTo((int) $feed->firm_id);

            try {
                return $this->build($feed, $user);
            } finally {
                $database->bypass();
            }
        }, $user);

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="lex-ph.ics"',
            'Cache-Control' => 'private, max-age=300',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    private function build(CalendarFeed $feed, User $user): string
    {
        $base = rtrim(config('app.frontend_url'), '/');
        $calendar = new ICalendar($feed->scope === 'firm' ? 'Lex PH: firm calendar' : "Lex PH: {$user->name}");

        $deadlines = MatterDeadline::query()
            ->whereIn('status', [DeadlineStatus::Pending->value, DeadlineStatus::Missed->value, DeadlineStatus::Completed->value])
            ->whereBetween('due_date', [today()->subDays(60)->toDateString(), today()->addYear()->toDateString()])
            ->when($feed->scope === 'mine', fn ($q) => $q->where(fn ($q) => $q
                ->where('assigned_to', $user->id)
                ->orWhereHas('matter', fn ($m) => $m->where('responsible_lawyer_id', $user->id))))
            ->with(['matter:id,reference,title,case_number,court,court_branch', 'rule:id,name,legal_basis'])
            ->orderBy('due_date')
            ->limit(2000)
            ->get();

        foreach ($deadlines as $d) {
            $kind = match ($d->kind) {
                DeadlineKind::Hearing => 'Hearing',
                DeadlineKind::Task => 'Task',
                default => 'Deadline',
            };
            $done = $d->status === DeadlineStatus::Completed ? '✓ ' : '';
            $timed = $d->kind === DeadlineKind::Hearing && $d->due_time;
            $start = $timed
                ? CarbonImmutable::parse($d->due_date->toDateString().' '.$d->due_time, ICalendar::TIMEZONE)
                : CarbonImmutable::parse($d->due_date->toDateString());

            $calendar->event([
                'uid' => "deadline-{$d->id}@".parse_url($base, PHP_URL_HOST),
                'stamp' => $d->updated_at ?? now(),
                'start' => $start,
                'all_day' => ! $timed,
                'summary' => $feed->show_details
                    ? "{$done}{$kind}: {$d->title} ({$d->matter?->title})"
                    : "{$done}{$kind}: {$d->matter?->reference}",
                'description' => $feed->show_details
                    ? collect([
                        $d->matter?->reference,
                        $d->matter?->case_number ? "Case no. {$d->matter->case_number}" : null,
                        $d->rule ? "{$d->rule->name} ({$d->rule->legal_basis})" : null,
                        $d->notes,
                        "Open in Lex PH: {$base}/matters/{$d->matter_id}?tab=deadlines",
                    ])->filter()->implode("\n")
                    : "Details in Lex PH: {$base}/matters/{$d->matter_id}?tab=deadlines",
                'location' => $feed->show_details ? ($d->location ?: collect([$d->matter?->court, $d->matter?->court_branch])->filter()->implode(', ')) : null,
                'url' => "{$base}/matters/{$d->matter_id}?tab=deadlines",
                'alarm_minutes' => $done ? null : ($timed ? 60 : 24 * 60),
            ]);
        }

        return $calendar->render();
    }

    private function summary(CalendarFeed $feed): array
    {
        return [
            'scope' => $feed->scope,
            'show_details' => $feed->show_details,
            'created_at' => $feed->created_at?->toIso8601String(),
            'last_accessed_at' => $feed->last_accessed_at?->toIso8601String(),
        ];
    }
}
