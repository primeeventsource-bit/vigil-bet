<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The age gate, as arithmetic, in one place.
 *
 * The signup form collects a full date of birth because that is the only
 * honest way to ask the question, and then throws the month and day away:
 * only the year is stored. So the check has to happen here, while the
 * whole date is still in hand, and it has to be the conservative one.
 *
 * Conservative means someone born on 31 December is treated as having
 * their birthday on 31 December, not on 1 January. A gate that rounded the
 * other way would admit people up to a year under the limit, which is the
 * one failure mode that actually matters.
 */
final class Age
{
    public static function minimum(): int
    {
        return (int) config('vigil.minimum_age');
    }

    /** Whole years old on a given day, from a full date of birth. */
    public static function yearsOld(Carbon $birthDate, ?Carbon $on = null): int
    {
        return (int) $birthDate->copy()->startOfDay()
            ->diffInYears(($on ?? Carbon::now())->copy()->startOfDay());
    }

    public static function meetsMinimum(Carbon $birthDate, ?Carbon $on = null): bool
    {
        return self::yearsOld($birthDate, $on) >= self::minimum();
    }

    /**
     * The latest birth date that still clears the gate, for the `max`
     * attribute on the date field. A browser refusing the date before the
     * form is submitted is kinder than a validation error, and the server
     * checks again regardless - this is a convenience, never the gate.
     */
    public static function latestEligibleBirthDate(?Carbon $on = null): Carbon
    {
        return ($on ?? Carbon::now())->copy()->startOfDay()->subYears(self::minimum());
    }
}
