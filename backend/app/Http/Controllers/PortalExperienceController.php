<?php

namespace App\Http\Controllers;

use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Enums\DeadlineStatus;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Feedback\ClientFeedback;
use App\Domain\Feedback\MatterFeedback;
use App\Domain\Matters\Models\Client;
use App\Support\Calendar\ICalendar;
use App\Support\Localization\PortalLocale;
use App\Support\Tenancy\DatabaseTenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The portal client's feedback on closed matters, and their private
 * calendar subscription to their own hearings.
 */
class PortalExperienceController extends Controller
{
    public function respond(Request $request, int $feedback, ClientFeedback $service): JsonResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $model = MatterFeedback::query()->where('client_id', $this->client($request)->id)->findOrFail($feedback);
        $service->respond($model, (int) $validated['rating'], $validated['comment'] ?? null);

        return response()->json(self::feedbackPayload($model));
    }

    public static function feedbackPayload(?MatterFeedback $f): ?array
    {
        return $f ? [
            'id' => $f->id,
            'rating' => $f->rating,
            'comment' => $f->comment,
            'responded_at' => $f->responded_at?->toIso8601String(),
            'can_change' => $f->followed_up_at === null,
        ] : null;
    }

    public function calendar(Request $request): JsonResponse
    {
        $client = $this->client($request);

        return response()->json(['enabled' => $client->calendar_token_hash !== null, 'created_at' => $client->calendar_created_at?->toIso8601String(), 'last_accessed_at' => $client->calendar_accessed_at?->toIso8601String()]);
    }

    /** Create the subscription, or replace it (the old link stops working). The link is shown only now. */
    public function createCalendar(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $token = Str::random(40);
        $client->forceFill(['calendar_token_hash' => hash('sha256', $token), 'calendar_created_at' => now(), 'calendar_accessed_at' => null])->save();

        return response()->json(['enabled' => true, 'url' => url("/api/portal-calendar/{$token}.ics")], 201);
    }

    public function deleteCalendar(Request $request): JsonResponse
    {
        $this->client($request)->forceFill(['calendar_token_hash' => null, 'calendar_created_at' => null, 'calendar_accessed_at' => null])->save();

        return response()->json(null, 204);
    }

    /** The public feed: the unguessable token is the credential; it stops with portal access. */
    public function feed(string $token, TenantContext $tenant, DatabaseTenancy $database): Response
    {
        $client = Client::withoutGlobalScopes()->where('calendar_token_hash', hash('sha256', $token))->first();
        abort_if($client === null || ! $client->portal_enabled, 404);

        if ($client->calendar_accessed_at === null || $client->calendar_accessed_at->lt(now()->subMinutes(10))) {
            $client->forceFill(['calendar_accessed_at' => now()])->saveQuietly();
        }

        $ics = $tenant->runAs((int) $client->firm_id, function () use ($client, $database) {
            $database->restrictTo((int) $client->firm_id);
            try {
                app()->setLocale(PortalLocale::normalize($client->locale));

                return $this->build($client);
            } finally {
                $database->bypass();
            }
        }, $client);

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="hearings.ics"',
            'Cache-Control' => 'private, max-age=300',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /** The client's hearings only: never the firm's internal deadlines or tasks. */
    private function build(Client $client): string
    {
        $base = rtrim(config('app.frontend_url'), '/');
        $calendar = new ICalendar(__('Hearings: :name', ['name' => $client->name]));

        $hearings = MatterDeadline::query()
            ->where('kind', DeadlineKind::Hearing->value)
            ->whereIn('status', [DeadlineStatus::Pending->value, DeadlineStatus::Completed->value])
            ->whereBetween('due_date', [today()->subDays(60)->toDateString(), today()->addYear()->toDateString()])
            ->whereHas('matter', fn ($q) => $q->where('client_id', $client->id))
            ->with('matter:id,title,court,court_branch')
            ->orderBy('due_date')
            ->limit(500)
            ->get();

        foreach ($hearings as $h) {
            $timed = (bool) $h->due_time;
            $calendar->event([
                'uid' => "portal-hearing-{$h->id}@".parse_url($base, PHP_URL_HOST),
                'stamp' => $h->updated_at ?? now(),
                'start' => $timed ? CarbonImmutable::parse($h->due_date->toDateString().' '.$h->due_time, ICalendar::TIMEZONE) : CarbonImmutable::parse($h->due_date->toDateString()),
                'all_day' => ! $timed,
                'summary' => __('Hearing: :title', ['title' => $h->matter?->title]),
                'description' => collect([$h->title, __('Details in your client portal: :url', ['url' => "{$base}/portal/matters/{$h->matter_id}"])])->filter()->implode("\n"),
                'location' => $h->location ?: collect([$h->matter?->court, $h->matter?->court_branch])->filter()->implode(', '),
                'url' => "{$base}/portal/matters/{$h->matter_id}",
                // A reminder the day before each hearing still to come.
                'alarm_minutes' => $h->status === DeadlineStatus::Completed ? null : 24 * 60,
            ]);
        }

        return $calendar->render();
    }

    private function client(Request $request): Client
    {
        return $request->user('client');
    }
}
