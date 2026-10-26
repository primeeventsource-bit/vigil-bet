<?php

declare(strict_types=1);

use App\Models\Opinion;
use App\Models\User;
use App\Models\VlBoardEntry;
use App\Services\EdgeLab\Mirror;
use App\Services\Record\OpinionStanding;

/*
 * An opinion is published once and never rewritten, which is the whole
 * reason the record is worth reading. But it stays on the card until
 * kickoff while the model keeps scanning, so Saturday's 67% can still be
 * on screen on Monday beside an engine that now says 34% and passes.
 *
 * The published number is not the problem. Showing it as though it were
 * today's view is. So the present view goes next to it, and the model's
 * favourite goes underneath - because "likely to win" and "worth
 * backing" are different sentences and the card only ever said the
 * second.
 */

/*
 * A real engine event, because an opinion's event_id is a foreign key
 * to it. Its id is also what the mirrored board entry points at: in
 * this app the engine's event id is the edge_lab id.
 */
function standingEvent(): int
{
    return (int) engineGame('Philadelphia Eagles', 'Dallas Cowboys', [
        'sport' => 'nfl',
        'starts_at' => now()->addHours(6),
    ])->id;
}

function standingOpinion(int $eventId, float $prob = 0.67): Opinion
{
    return Opinion::create([
        'event_id' => $eventId,
        'sport' => 'nfl',
        'market' => 'h2h',
        'side' => 'home',
        'line' => null,
        'selection' => 'Philadelphia Eagles',
        'matchup' => 'Dallas Cowboys at Philadelphia Eagles',
        'price_american' => 180,
        'model_prob' => $prob,
        'kickoff_at' => now()->addHours(6),
        'published_at' => now()->subDays(2),
        'created_at' => now()->subDays(2),
    ]);
}

function standingEntry(int $eventId, int $id, string $side, string $selection, float $prob, string $recommendation, ?int $price = null): void
{
    $row = boardRow($id, [
        'event_id' => $eventId,
        'sport' => 'nfl',
        'market' => 'h2h',
        'side' => $side,
        'selection' => $selection,
        'model_prob' => $prob,
        'recommendation' => $recommendation,
        'market_verdict' => 'calibrated',
        'best_price_american' => $price,
    ]);

    app(Mirror::class)->write(VlBoardEntry::class, $row, [
        'edge_lab_id' => (int) $row['id'],
        'edge_lab_event_id' => $eventId,
        'sport' => 'nfl',
        'market' => 'h2h',
        'side' => $side,
        'line' => null,
        'selection' => $selection,
        'model_prob' => $prob,
        'best_price_american' => $price,
        'market_verdict' => 'calibrated',
        'recommendation' => $recommendation,
        'pass_reason' => $recommendation === 'pass' ? 'negative_edge' : null,
    ]);
}

function standingFor(Opinion $opinion): ?array
{
    return app(OpinionStanding::class)->for(collect([$opinion]))->get($opinion->id);
}

it('says plainly when the engine no longer holds a published opinion', function () {
    $eventId = standingEvent();
    $opinion = standingOpinion($eventId, 0.67);

    standingEntry($eventId, 1, 'home', 'Philadelphia Eagles', 0.34, 'pass', 185);
    standingEntry($eventId, 2, 'away', 'Dallas Cowboys', 0.66, 'pass', -225);

    $now = standingFor($opinion);

    expect($now['held'])->toBeFalse()
        ->and(round($now['current'], 2))->toBe(0.34)
        ->and($now['pass_reason'])->toBe('negative_edge');

    // The published figure is untouched. It is the record.
    expect(round((float) $opinion->fresh()->model_prob, 2))->toBe(0.67);
});

it('names the favourite even when it will not back it', function () {
    $eventId = standingEvent();
    $opinion = standingOpinion($eventId, 0.67);

    standingEntry($eventId, 1, 'home', 'Philadelphia Eagles', 0.34, 'pass', 185);
    standingEntry($eventId, 2, 'away', 'Dallas Cowboys', 0.66, 'pass', -225);

    $likely = standingFor($opinion)['likely'];

    /*
     * The model makes Dallas favourite and the price is shorter than
     * that, so there is no bet here. Both halves are said, because the
     * first without the second reads as a tip.
     */
    expect($likely['selection'])->toBe('Dallas Cowboys')
        ->and(round($likely['prob'], 2))->toBe(0.66)
        ->and($likely['price'])->toBe(-225)
        ->and($likely['backable'])->toBeFalse();
});

it('marks a favourite it would back as backable', function () {
    $eventId = standingEvent();
    $opinion = standingOpinion($eventId, 0.67);

    standingEntry($eventId, 1, 'home', 'Philadelphia Eagles', 0.70, 'bet', 150);
    standingEntry($eventId, 2, 'away', 'Dallas Cowboys', 0.30, 'pass', -200);

    $now = standingFor($opinion);

    expect($now['held'])->toBeTrue()
        ->and($now['likely']['backable'])->toBeTrue();
});

it('stays quiet when the number has barely moved', function () {
    $eventId = standingEvent();
    $opinion = standingOpinion($eventId, 0.67);

    standingEntry($eventId, 1, 'home', 'Philadelphia Eagles', 0.671, 'bet', 180);

    // A tenth of a point is the model breathing, not a change of mind.
    expect(standingFor($opinion)['drifted'])->toBeFalse();
});

it('speaks up once the number has moved past the threshold', function () {
    $eventId = standingEvent();
    $opinion = standingOpinion($eventId, 0.67);

    standingEntry($eventId, 1, 'home', 'Philadelphia Eagles', 0.60, 'bet', 180);

    expect(standingFor($opinion)['drifted'])->toBeTrue();
});

it('says nothing at all rather than guessing when the board has no row', function () {
    $eventId = standingEvent();
    $opinion = standingOpinion($eventId, 0.67);

    /*
     * No current entry means no current view, which is not the same as
     * a view that has not changed. Inventing the second would be the
     * card asserting the engine still agrees with itself.
     */
    expect(standingFor($opinion))->toBeNull();
});

it('reads the latest scan rather than the first', function () {
    $eventId = standingEvent();
    $opinion = standingOpinion($eventId, 0.67);

    standingEntry($eventId, 1, 'home', 'Philadelphia Eagles', 0.67, 'bet', 180);
    standingEntry($eventId, 2, 'home', 'Philadelphia Eagles', 0.34, 'pass', 185);

    $now = standingFor($opinion);

    expect($now['held'])->toBeFalse()
        ->and(round($now['current'], 2))->toBe(0.34);
});

/*
 * The card itself, because the service being right is not the point.
 * What was wrong was the page showing a two-day-old number as today's
 * view, and only rendering it proves that stopped.
 */
it('says on the card that a published opinion is no longer held', function () {
    $eventId = standingEvent();
    standingOpinion($eventId, 0.67);

    standingEntry($eventId, 1, 'home', 'Philadelphia Eagles', 0.34, 'pass', 185);
    standingEntry($eventId, 2, 'away', 'Dallas Cowboys', 0.66, 'pass', -225);

    $user = User::forceCreate([
        'name' => 'Standing Reader',
        'email' => 'standing@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)->get(route('today'))
        ->assertOk()
        // The published claim is still there, unaltered.
        ->assertSee('Philadelphia Eagles')
        // Beside it, what we think now.
        ->assertSee('We no longer hold this')
        ->assertSee('now 34%')
        // And who we expect to win, said as a different thing from a pick.
        ->assertSee('Likely winner')
        ->assertSee('Dallas Cowboys')
        ->assertSee('not a pick: the price is shorter than our number');
});
