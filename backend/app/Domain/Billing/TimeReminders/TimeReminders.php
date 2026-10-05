<?php

namespace App\Domain\Billing\TimeReminders;

use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\TimeReminders\Notifications\MissingTimeReminder;
use App\Domain\Billing\TimeReminders\Notifications\WeeklyTimeSummary;
use App\Domain\Deadlines\Services\DeadlineCalculator;
use App\Domain\Matters\Models\Firm;
use App\Enums\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Time not logged the same day is often never billed. Each working morning,
 * whoever does matter work and logged less than their daily target on the
 * previous working day is reminded; on Mondays, the managing partner gets
 * last week's hours against target for everyone. Off until the firm turns
 * it on; a person's own target can be set, or set to 0 to leave them out.
 */
class TimeReminders
{
    public function __construct(private readonly DeadlineCalculator $calendar) {}

    /** Daily: reminders about the previous working day. */
    public function remindMissing(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        if (! $this->calendar->isWorkingDay($today)) {
            return 0;
        }
        $day = $this->previousWorkingDay($today);
        $sent = 0;

        $this->eachFirm(function (Firm $firm) use ($day, &$sent) {
            $people = $this->people($firm);
            $logged = $this->minutes($people->pluck('id'), $day, $day);

            foreach ($people as $user) {
                $target = $this->target($user, $firm);
                $minutes = (int) ($logged[$user->id] ?? 0);
                if ($minutes >= $target || $user->time_reminded_for?->toDateString() === $day->toDateString()) {
                    continue;
                }
                $claimed = DB::table('users')->where('id', $user->id)
                    ->where(fn ($q) => $q->whereNull('time_reminded_for')->orWhere('time_reminded_for', '<>', $day->toDateString()))
                    ->update(['time_reminded_for' => $day->toDateString()]) === 1;
                if ($claimed) {
                    $user->notify(new MissingTimeReminder($day, $minutes, $target));
                    $sent++;
                }
            }
        });

        return $sent;
    }

    /** Mondays: last week's hours against target, to the managing partners. */
    public function sendWeeklySummary(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $from = $today->subWeek()->startOfWeek();
        $to = $from->endOfWeek()->startOfDay();
        $sent = 0;

        $this->eachFirm(function (Firm $firm) use ($from, $to, &$sent) {
            $rows = $this->summary($firm, $from, $to);
            if ($rows === []) {
                return;
            }
            User::query()->where('is_active', true)->where('role', Role::ManagingPartner->value)->get()
                ->each(function (User $partner) use ($from, $to, $rows, &$sent) {
                    $partner->notify(new WeeklyTimeSummary($from, $to, $rows));
                    $sent++;
                });
        });

        return $sent;
    }

    /**
     * Hours against target for everyone with one, over the working days in the range.
     *
     * @return list<array{name: string, minutes: int, target: int}>
     */
    public function summary(Firm $firm, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $workingDays = 0;
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $workingDays += $this->calendar->isWorkingDay($d) ? 1 : 0;
        }
        $people = $this->people($firm);
        $logged = $this->minutes($people->pluck('id'), $from, $to);

        return $people
            ->map(fn (User $u) => ['name' => $u->name, 'minutes' => (int) ($logged[$u->id] ?? 0), 'target' => $this->target($u, $firm) * $workingDays])
            ->sortBy(fn ($r) => $r['target'] ? $r['minutes'] / $r['target'] : 1)
            ->values()->all();
    }

    public function previousWorkingDay(CarbonImmutable $date): CarbonImmutable
    {
        $day = $date->subDay();
        while (! $this->calendar->isWorkingDay($day)) {
            $day = $day->subDay();
        }

        return $day;
    }

    private function eachFirm(callable $fn): void
    {
        Firm::query()->where('time_reminders_enabled', true)->get()
            ->each(fn (Firm $firm) => app(TenantContext::class)->runAs($firm->id, fn () => $fn($firm)));
    }

    /** Active people who do matter work and have a target. */
    private function people(Firm $firm): Collection
    {
        $roles = array_map(fn (Role $r) => $r->value, array_filter(Role::cases(), fn (Role $r) => $r->canWorkMatters()));

        return User::query()->where('is_active', true)->whereIn('role', $roles)->orderBy('name')->get()
            ->filter(fn (User $u) => $this->target($u, $firm) > 0)->values();
    }

    private function target(User $user, Firm $firm): int
    {
        return $user->daily_target_minutes ?? $firm->daily_target_minutes;
    }

    /** @return array<int, int> minutes by user */
    private function minutes(Collection $userIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return TimeEntry::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('user_id')
            ->pluck(DB::raw('sum(minutes)'), 'user_id')
            ->map(fn ($m) => (int) $m)
            ->all();
    }
}
