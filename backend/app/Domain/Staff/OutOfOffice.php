<?php

namespace App\Domain\Staff;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * While someone is away, whoever covers for them also gets their deadline
 * reminders, missed-deadline alerts and new assignments, so nothing waits
 * in the inbox of a person on leave. The person away still gets them too.
 */
class OutOfOffice
{
    public static function isAway(User $user, ?CarbonImmutable $on = null): bool
    {
        $day = ($on ?? CarbonImmutable::today())->toDateString();

        return $user->away_from !== null && $user->away_until !== null
            && $user->away_from->toDateString() <= $day && $user->away_until->toDateString() >= $day;
    }

    /** The active colleague covering for $user today, if they are away. */
    public static function coverFor(User $user, ?CarbonImmutable $on = null): ?User
    {
        if (! self::isAway($user, $on) || $user->cover_user_id === null) {
            return null;
        }
        $cover = User::withoutGlobalScopes()->where('firm_id', $user->firm_id)->where('is_active', true)->find($user->cover_user_id);

        // A cover who is away too does not help.
        return $cover && ! self::isAway($cover, $on) ? $cover : null;
    }

    /**
     * The recipients plus whoever covers for those of them who are away.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    public static function withCover(Collection $users, ?CarbonImmutable $on = null): Collection
    {
        return $users->merge($users->map(fn (User $u) => self::coverFor($u, $on))->filter())->unique('id')->values();
    }
}
