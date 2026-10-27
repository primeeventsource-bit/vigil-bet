<?php

declare(strict_types=1);

// The record page is behind the verdict gate now, so a test that
// reads it has to put a market through first.
beforeEach(fn () => provenMarket());

use App\Models\Opinion;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
 * A kickoff is stored in UTC and read on the sport's own clock.
 *
 * The storage is right and is not what these are about. What they are
 * about is the gap between the two: a one o'clock Sunday kickoff printed
 * straight out of the column reads 17:00, which is not a formatting
 * nicety. It is a wrong number on a public page, and wrong in the
 * direction that makes a finished game look like one yet to start - so
 * the row beside it saying `pending` reads as a product that has not
 * noticed rather than one waiting on a box score.
 */

function localTimeOpinion(CarbonImmutable $kickoff): Opinion
{
    $event = engineGame('Cleveland Browns', 'Tennessee Titans', [
        'sport' => 'nfl',
        'starts_at' => $kickoff,
    ]);

    return Opinion::create([
        'event_id' => $event->id,
        'sport' => 'nfl',
        'market' => 'h2h',
        'side' => 'away',
        'line' => null,
        'selection' => 'Cleveland Browns',
        'matchup' => 'Cleveland Browns at Tennessee Titans',
        'price_american' => -135,
        'model_prob' => 0.65,
        'kickoff_at' => $kickoff,
        'published_at' => $kickoff->subHours(4),
        'created_at' => $kickoff->subHours(4),
    ]);
}

it('shows a kickoff on the clock the game was played on', function () {
    // 17:00 UTC on 25 October 2026: the traditional one o'clock Sunday
    // slot, and the exact row that was reported as reading wrong.
    localTimeOpinion(CarbonImmutable::parse('2026-10-25 17:00:00', 'UTC'));

    $this->get('/opinions')
        ->assertOk()
        ->assertSee('25 Oct 13:00')
        ->assertDontSee('25 Oct 17:00');
});

it('reads the zone from configuration rather than hardcoding one', function () {
    config(['app.display_timezone' => 'America/Los_Angeles']);

    localTimeOpinion(CarbonImmutable::parse('2026-10-25 17:00:00', 'UTC'));

    $this->get('/opinions')->assertOk()->assertSee('25 Oct 10:00');
});

it('names the zone where a template asks for it', function () {
    // The published-pick header prints `T`, which said UTC while the
    // time beside it was being read as Eastern.
    expect(Clock::format(CarbonImmutable::parse('2026-10-25 17:00:00', 'UTC'), 'j F Y, H:i T'))
        ->toBe('25 October 2026, 13:00 EDT');
});

it('has nothing to say about a time that is not known yet', function () {
    expect(Clock::format(null))->toBeNull()
        ->and(Clock::local(null))->toBeNull()
        ->and(Clock::dayOf(null))->toBeNull();
});

/*
 * The boundary, not just the clock face.
 *
 * A day bounded in UTC ends at eight in the evening Eastern, which is
 * before the Sunday night game has kicked off. Filtering the board by
 * Sunday would drop it and file it under Monday - the same error as the
 * clock face, one that reads as a missing fixture rather than a wrong
 * number.
 */
it('bounds a day on the display clock, not the stored one', function () {
    [$from, $to] = Clock::dayBounds('2026-10-25');

    expect($from->toDateTimeString())->toBe('2026-10-25 04:00:00')
        ->and($to->toDateTimeString())->toBe('2026-10-26 03:59:59')
        ->and($from->timezone->getName())->toBe('UTC');
});

it('files a Sunday night kickoff under the Sunday it was played on', function () {
    // 20:20 Eastern on Sunday is 00:20 UTC on Monday.
    $kickoff = CarbonImmutable::parse('2026-10-26 00:20:00', 'UTC');

    [$from, $to] = Clock::dayBounds('2026-10-25');

    expect($kickoff->between($from, $to))->toBeTrue()
        ->and(Clock::dayOf($kickoff))->toBe('2026-10-25');
});

/*
 * The rule, rather than twelve places that currently follow it.
 *
 * This is the failure that does not announce itself: a template that
 * formats the column directly renders a perfectly laid out wrong time,
 * and no test of that page fails unless it asserts the digits. So the
 * templates are scanned instead.
 *
 * Narrow on purpose. Formatting a date that was never an instant - a
 * month heading, a grouping key that is already a local date string -
 * is correct and common, so only columns are forbidden.
 */
it('lets no template format a stored instant itself', function () {
    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => preg_match(
            "/_at'?\]?\s*\??->format\(/",
            File::get($file->getPathname()),
        ) === 1)
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([], implode(', ', $offenders)
        .' format a timestamp column directly. Use <x-local-time> so it is'
        .' shown on the clock the product is read on.');
});
