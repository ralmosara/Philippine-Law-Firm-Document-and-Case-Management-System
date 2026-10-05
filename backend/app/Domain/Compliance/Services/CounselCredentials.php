<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Notifications\CredentialsRenewalDue;
use App\Domain\Matters\Models\Firm;
use App\Enums\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The PTR and IBP details printed under a lawyer's signature (Rule 7,
 * Sec. 3) must be for the current year. This says what is missing or out
 * of date, formats the signature lines, and reminds lawyers in January.
 */
class CounselCredentials
{
    /** January days on which lawyers with out-of-date details are reminded. */
    public const REMINDER_DAYS = [2, 15, 28];

    /** @return list<string> what is missing or out of date, in plain words */
    public function problems(User $user, ?CarbonImmutable $today = null): array
    {
        $year = ($today ?? CarbonImmutable::today())->year;
        $problems = [];

        if (blank($user->ptr_number)) {
            $problems[] = 'No PTR number recorded.';
        } elseif ($user->ptr_date === null) {
            $problems[] = 'The PTR date is not recorded, so it cannot be checked.';
        } elseif ($user->ptr_date->year !== $year) {
            $problems[] = "The PTR is for {$user->ptr_date->year}, not {$year}.";
        }

        if (blank($user->ibp_number)) {
            $problems[] = 'No IBP number recorded.';
        } elseif (! $user->ibp_lifetime && $user->ibp_date === null) {
            $problems[] = 'The IBP payment date is not recorded, so it cannot be checked.';
        } elseif (! $user->ibp_lifetime && $user->ibp_date->year !== $year) {
            $problems[] = "IBP dues are paid for {$user->ibp_date->year}, not {$year}.";
        }

        return $problems;
    }

    /** "PTR No. 7654321, 01/06/2026, Makati City" (number alone if no date is recorded). */
    public function ptrLine(User $user): string
    {
        return 'PTR No. '.($user->ptr_number ?: '______').$this->dated($user->ptr_number, $user->ptr_date, $user->ptr_place);
    }

    public function ibpLine(User $user): string
    {
        if ($user->ibp_lifetime && filled($user->ibp_number)) {
            return 'IBP Lifetime Member No. '.$user->ibp_number.($user->ibp_chapter ? ", {$user->ibp_chapter}" : '');
        }

        return 'IBP No. '.($user->ibp_number ?: '______').$this->dated($user->ibp_number, $user->ibp_date, $user->ibp_chapter);
    }

    /** Daily: on the January reminder days, every active lawyer whose details are not current. */
    public function sendReminders(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        if ($today->month !== 1 || ! in_array($today->day, self::REMINDER_DAYS, true)) {
            return 0;
        }
        $lawyers = array_map(fn (Role $r) => $r->value, array_filter(Role::cases(), fn (Role $r) => $r->isLawyer()));
        $sent = 0;

        Firm::query()->pluck('id')->each(function (int $firmId) use ($today, $lawyers, &$sent) {
            app(TenantContext::class)->runAs($firmId, function () use ($today, $lawyers, &$sent) {
                User::query()->where('is_active', true)->whereIn('role', $lawyers)->get()
                    ->each(function (User $user) use ($today, &$sent) {
                        if ($problems = $this->problems($user, $today)) {
                            $user->notify(new CredentialsRenewalDue($today->year, $problems));
                            $sent++;
                        }
                    });
            });
        });

        return $sent;
    }

    private function dated(?string $number, ?\DateTimeInterface $date, ?string $place): string
    {
        if (blank($number) || $date === null) {
            return '';
        }

        return ', '.$date->format('m/d/Y').($place ? ", {$place}" : '');
    }
}
