<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Opinion;
use App\Models\OpinionResult;
use App\Models\PublishedPick;
use App\Services\Record\OpinionPublisher;
use App\Services\Record\OpinionRecord;
use App\Services\Record\OpinionSettler;
use App\Services\Record\PublicRecord;

/*
|--------------------------------------------------------------------------
| The published opinion record
|--------------------------------------------------------------------------
|
| An opinion is a weaker claim than a pick, and the only thing that
| separates it from an assertion is every one being graded in public.
| So these tests are mostly about what the page refuses to say: no rate
| without its sample, and a dash rather than a zero when nothing has been
| measured.
|
*/

function settledOpinion(string $status, int $price = -110, float $prob = 0.62): Opinion
{
    $bet = opinionCandidate(['best_price_american' => $price, 'model_prob' => $prob], hoursToKickoff: 3);

    app(OpinionPublisher::class)->run();

    $opinion = Opinion::query()->orderByDesc('id')->firstOrFail();

    // The clock moves rather than the row: opinions refuse to be written
    // after kickoff, so settlement is reached the way production reaches
    // it, by the game happening.
    $home = $status === 'won' ? 30 : 10;
    $away = $status === 'won' ? 10 : 30;

    Event::query()->whereKey($opinion->event_id)->update([
        'status' => 'final',
        'final_home_score' => $home,
        'final_away_score' => $away,
    ]);

    return $opinion;
}

it('shows no win rate at all until something has been graded', function () {
    opinionCandidate();
    app(OpinionPublisher::class)->run();

    $summary = app(OpinionRecord::class)->summary();

    /*
     * Null, not zero. An unmeasured figure and a measured zero look the
     * same on a page and are different claims - which is the whole of
     * §0.7 and the reason this is the first test here.
     */
    expect($summary['published'])->toBe(1)
        ->and($summary['graded'])->toBe(0)
        ->and($summary['win_rate'])->toBeNull()
        ->and($summary['units'])->toBeNull()
        ->and($summary['roi'])->toBeNull()
        ->and($summary['mean_clv'])->toBeNull();
});

it('counts a graded opinion once it has a result', function () {
    settledOpinion('won');

    $this->travel(5)->hours();
    app(OpinionSettler::class)->run();

    $summary = app(OpinionRecord::class)->summary();

    expect($summary['graded'])->toBe(1)
        ->and($summary['win_rate'])->toBe(1.0)
        // -110 returns 0.909 on a win.
        ->and($summary['units'])->toBe(0.91);
});

it('shows the losers', function () {
    // The reason anybody would believe the page. A record that only
    // reported winners would not be a record.
    settledOpinion('lost');

    $this->travel(5)->hours();
    app(OpinionSettler::class)->run();

    $summary = app(OpinionRecord::class)->summary();

    expect($summary['graded'])->toBe(1)
        ->and($summary['win_rate'])->toBe(0.0)
        ->and($summary['units'])->toBe(-1.0)
        ->and(OpinionResult::sole()->status)->toBe('lost');
});

it('reports calibration in bands, each with its sample', function () {
    settledOpinion('won', prob: 0.72);
    settledOpinion('lost', prob: 0.74);

    $this->travel(5)->hours();
    app(OpinionSettler::class)->run();

    $bands = app(OpinionRecord::class)->calibration();

    expect($bands)->toHaveCount(1);

    $band = $bands[0];

    // Said 70-79, won one of two. Both numbers shown, neither alone.
    expect($band['stated'])->toBe(70)
        ->and($band['n'])->toBe(2)
        ->and($band['actual'])->toBe(0.5);
});

it('settles an opinion once, however often the settler runs', function () {
    settledOpinion('won');

    $this->travel(5)->hours();

    $settler = app(OpinionSettler::class);

    expect($settler->run()['settled'])->toBe(1)
        ->and($settler->run()['settled'])->toBe(0)
        ->and(OpinionResult::count())->toBe(1);
});

it('leaves an opinion unsettled rather than guessing a score', function () {
    opinionCandidate(hoursToKickoff: 1);
    app(OpinionPublisher::class)->run();

    $this->travel(3)->hours();

    // Finished, but the score has not arrived.
    Event::query()->update(['status' => 'final']);

    $outcome = app(OpinionSettler::class)->run();

    expect($outcome['settled'])->toBe(0)
        ->and($outcome['unresolved'])->toBe(1)
        ->and(OpinionResult::count())->toBe(0);
});

it('serves the page with nothing published', function () {
    // The state it will be in for its first hours, and the one most
    // likely to read as a record of nothing if it is built carelessly.
    $this->get(route('opinions'))
        ->assertOk()
        ->assertSee('not proven picks', false)
        ->assertSee('Nothing published yet');
});

it('serves the page with an opinion on it', function () {
    opinionCandidate();
    app(OpinionPublisher::class)->run();

    $this->get(route('opinions'))
        ->assertOk()
        ->assertSee('Detroit Red Wings')
        ->assertSee('nothing graded yet')
        ->assertSee('pending');
});

it('never shows the opinion record as the public track record', function () {
    /*
     * The boundary the whole design rests on. Summing a weaker claim
     * into a stronger one destroys the stronger one, and the public
     * record is the only page a sceptic will take seriously.
     */
    settledOpinion('won');

    $this->travel(5)->hours();
    app(OpinionSettler::class)->run();

    expect(PublishedPick::count())->toBe(0)
        ->and(app(PublicRecord::class)->summary()['settled'] ?? 0)->toBe(0);
});
