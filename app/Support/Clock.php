<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The clock the product is read on, in one place.
 *
 * Every instant in this database is UTC and that is right: it is the only
 * storage that survives a daylight saving change without lying about
 * what happened. But nobody reads a board in UTC. A one o'clock kickoff
 * rendered straight out of the column says 17:00, which is not a
 * typographical problem - it is a wrong number on a public page, and it
 * is wrong in the direction that makes a finished game look like one
 * that has not started.
 *
 * So the conversion happens here, and views ask for it rather than
 * calling `format()` on a column. `ShowsLocalTimeTest` enforces that,
 * because the failure is silent: a template that forgets renders a
 * perfectly formatted wrong time, and nothing about the page looks
 * broken.
 *
 * Day boundaries go through `day()` for the same reason. "Sunday's
 * games" means the sport's Sunday, which ends at midnight Eastern and
 * not five hours earlier.
 */
final class Clock
{
    public static function zone(): string
    {
        return (string) config('app.display_timezone', 'America/New_York');
    }

    /** The same instant, on the clock the product is read on. */
    public static function local(?DateTimeInterface $at): ?Carbon
    {
        return $at === null ? null : Carbon::instance($at)->setTimezone(self::zone());
    }

    /**
     * An instant, formatted for a reader.
     *
     * Null in, null out: a kickoff that is not known yet is not a time,
     * and the alternative is every caller guarding before it can ask.
     */
    public static function format(?DateTimeInterface $at, string $format = 'j M, H:i'): ?string
    {
        return self::local($at)?->format($format);
    }

    /** Right now, on that same clock. */
    public static function now(): Carbon
    {
        return Carbon::now(self::zone());
    }

    /**
     * A calendar day in the display zone, as the UTC instants that bound
     * it - which is what a `whereBetween` on a stored column needs.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function dayBounds(string $date): array
    {
        $day = Carbon::createFromFormat('Y-m-d', $date, self::zone());

        return [
            $day->copy()->startOfDay()->utc(),
            $day->copy()->endOfDay()->utc(),
        ];
    }

    /** Which day an instant belongs to, on the display clock. */
    public static function dayOf(?DateTimeInterface $at): ?string
    {
        return self::local($at)?->toDateString();
    }
}
