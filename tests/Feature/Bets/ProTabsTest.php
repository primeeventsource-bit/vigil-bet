<?php

declare(strict_types=1);

use App\Models\VlEvent;
use App\Models\VlModel;
use App\Models\VlOddsDisplay;
use App\Services\Tools\CrossBook;

function crossQuote(array $overrides = []): VlOddsDisplay
{
    return VlOddsDisplay::create(array_merge([
        'edge_lab_event_id' => 77,
        'book' => 'draftkings',
        'book_title' => 'DraftKings',
        'market' => 'h2h',
        'side' => 'home',
        'price_american' => -110,
        'quoted_at' => now()->subMinutes(5),
        'digest' => uniqid('', true),
        'observed_at' => now(),
    ], $overrides));
}

function crossGame(int $id = 77): VlEvent
{
    return VlEvent::create([
        'edge_lab_id' => $id, 'sport' => 'nfl', 'starts_at' => now()->addHours(3),
        'status' => 'scheduled', 'home_name' => 'Chiefs', 'away_name' => 'Bills',
        'home_abbreviation' => 'KC', 'away_abbreviation' => 'BUF',
        'payload' => [], 'digest' => 'e'.$id, 'observed_at' => now(),
    ]);
}

/* ----------------------------------------------------------------------
 | Model Lab
 ---------------------------------------------------------------------- */

function labModel(array $overrides = []): VlModel
{
    return VlModel::create(array_merge([
        'edge_lab_id' => random_int(1, 1_000_000),
        'sport' => 'nfl', 'market' => 'h2h', 'model_type' => 'logistic',
        'version' => 3, 'status' => 'active',
        'verdict' => 'unproven', 'allows_picks' => false,
        'predictions' => 1_284, 'log_loss_improvement' => 0.0231,
        'gate' => ['min_predictions' => 500, 'min_log_loss_improvement' => 0.01],
        'trained_at' => now()->subWeek(), 'payload' => [],
        'digest' => uniqid('', true), 'observed_at' => now(),
    ], $overrides));
}

it('shows the failures as well as the passes', function () {
    labModel(['sport' => 'nfl', 'market' => 'h2h', 'verdict' => 'proven', 'allows_picks' => true]);
    labModel(['sport' => 'nfl', 'market' => 'spreads', 'verdict' => 'negative', 'log_loss_improvement' => -0.028]);

    $this->actingAs(betUser('pro'))->get(route('models'))
        ->assertOk()
        // A page that only showed what passed would not be worth reading.
        ->assertSee('spreads')
        ->assertSee('-0.0280')
        ->assertSee('Allowed to sell');
});

it('states the gate the verdicts were measured against', function () {
    labModel();

    $this->actingAs(betUser('pro'))->get(route('models'))
        ->assertOk()
        ->assertSee('Min predictions')
        ->assertSee('500');
});

it('refuses to state one gate when the models disagree about it', function () {
    labModel(['market' => 'h2h', 'gate' => ['min_predictions' => 500]]);
    labModel(['market' => 'totals', 'gate' => ['min_predictions' => 1_000]]);

    $this->actingAs(betUser('pro'))->get(route('models'))
        ->assertOk()
        // Stating one while four were measured against another would be
        // the opposite of a transparency page.
        ->assertSee('not all measured against the same thresholds');
});

it('says the calibration chart is missing rather than drawing one', function () {
    labModel();

    $this->actingAs(betUser('pro'))->get(route('models'))
        ->assertOk()
        ->assertSee('Not available yet')
        ->assertSee('calibration_bins');
});

it('keeps model lab behind pro', function () {
    $this->actingAs(betUser(null))->get(route('models'))
        ->assertRedirect(route('upgrade', ['feature' => 'modellab']));
});

/* ----------------------------------------------------------------------
 | Tools: arbitrage and middles
 ---------------------------------------------------------------------- */

it('finds an arbitrage across two books', function () {
    crossGame();

    // +120 and +110 on opposite sides: implied 0.4545 + 0.4762 = 0.9307,
    // so between them the two prices pay out more than certainty.
    crossQuote(['book' => 'draftkings', 'side' => 'home', 'price_american' => 120]);
    crossQuote(['book' => 'fanduel', 'book_title' => 'FanDuel', 'side' => 'away', 'price_american' => 110]);

    $arbs = app(CrossBook::class)->arbitrage();

    expect($arbs)->toHaveCount(1)
        ->and($arbs->first()['profit'])->toBeGreaterThan(0.06);
});

it('finds nothing in a normally priced market', function () {
    crossGame();

    // -110 both ways is the book's hold. This is the normal state of
    // affairs and a scanner that found an arb here would be broken.
    crossQuote(['book' => 'draftkings', 'side' => 'home', 'price_american' => -110]);
    crossQuote(['book' => 'fanduel', 'book_title' => 'FanDuel', 'side' => 'away', 'price_american' => -110]);

    expect(app(CrossBook::class)->arbitrage())->toBeEmpty();
});

it('never calls one books own two sides an arbitrage', function () {
    crossGame();

    crossQuote(['book' => 'draftkings', 'side' => 'home', 'price_american' => 120]);
    crossQuote(['book' => 'draftkings', 'side' => 'away', 'price_american' => 110]);

    // A book does not price itself into an arb; if it looks like one the
    // data is stale.
    expect(app(CrossBook::class)->arbitrage())->toBeEmpty();
});

it('uses the newest quote per book and side, not a stale one', function () {
    crossGame();

    crossQuote(['book' => 'draftkings', 'side' => 'home', 'price_american' => 120, 'quoted_at' => now()->subHour()]);
    crossQuote(['book' => 'draftkings', 'side' => 'home', 'price_american' => -200, 'quoted_at' => now()]);
    crossQuote(['book' => 'fanduel', 'book_title' => 'FanDuel', 'side' => 'away', 'price_american' => 110]);

    // The old +120 would have been an arb. The current -200 is not, and
    // two stale quotes are not two prices a customer could take.
    expect(app(CrossBook::class)->arbitrage())->toBeEmpty();
});

it('finds a middle where the lines leave a gap', function () {
    crossGame();

    // Over 48.5 at one book, under 51.5 at another: 49, 50 and 51 win
    // both sides.
    crossQuote(['market' => 'totals', 'side' => 'over', 'line' => 48.5, 'book' => 'draftkings']);
    crossQuote(['market' => 'totals', 'side' => 'under', 'line' => 51.5, 'book' => 'fanduel', 'book_title' => 'FanDuel']);

    $middles = app(CrossBook::class)->middles();

    expect($middles)->toHaveCount(1)
        ->and($middles->first()['width'])->toBe(3.0)
        // Not free money: the cost column is what it costs when the
        // result lands outside the gap.
        ->and($middles->first()['cost'])->toBeGreaterThan(0);
});

it('does not call overlapping lines a middle', function () {
    crossGame();

    // Over 51.5 and under 48.5 is the opposite of a middle: nothing
    // wins both.
    crossQuote(['market' => 'totals', 'side' => 'over', 'line' => 51.5, 'book' => 'draftkings']);
    crossQuote(['market' => 'totals', 'side' => 'under', 'line' => 48.5, 'book' => 'fanduel', 'book_title' => 'FanDuel']);

    expect(app(CrossBook::class)->middles())->toBeEmpty();
});

it('ignores props when looking for arbitrage', function () {
    crossGame();

    crossQuote(['market_key' => 'player_points', 'player_name' => 'Somebody', 'side' => 'over', 'price_american' => 120, 'book' => 'draftkings']);
    crossQuote(['market_key' => 'player_points', 'player_name' => 'Somebody', 'side' => 'under', 'price_american' => 110, 'book' => 'fanduel']);

    // Props are a separate board. Mixing them in here would compare a
    // player line against a game line.
    expect(app(CrossBook::class)->arbitrage())->toBeEmpty();
});

it('says the tools only cover games somebody has opened', function () {
    $this->actingAs(betUser('pro'))->get(route('tools'))
        ->assertOk()
        ->assertSee('stored')
        ->assertSee('None in the stored quotes');
});

it('keeps tools behind pro', function () {
    $this->actingAs(betUser(null))->get(route('tools'))
        ->assertRedirect(route('upgrade', ['feature' => 'tools.crossbook']));
});

/* ----------------------------------------------------------------------
 | Props
 ---------------------------------------------------------------------- */

it('shops a prop line across books and marks the best price', function () {
    crossGame();

    crossQuote(['market' => 'player_points', 'market_key' => 'player_points', 'player_name' => 'Josh Allen',
        'side' => 'over', 'line' => 2.5, 'price_american' => -130, 'book' => 'draftkings']);
    crossQuote(['market' => 'player_points', 'market_key' => 'player_points', 'player_name' => 'Josh Allen',
        'side' => 'over', 'line' => 2.5, 'price_american' => 105, 'book' => 'fanduel', 'book_title' => 'FanDuel']);

    $this->actingAs(betUser('pro'))->get(route('props'))
        ->assertOk()
        ->assertSee('Josh Allen')
        // +105 beats -130, which is true across the sign boundary.
        ->assertSee('FanDuel')
        ->assertSee('+105')
        ->assertSee('235 pts');
});

it('never shows an edge or a probability on the props board', function () {
    crossGame();

    crossQuote(['market' => 'player_points', 'market_key' => 'player_points', 'player_name' => 'Josh Allen',
        'side' => 'over', 'line' => 2.5, 'price_american' => -130, 'book' => 'draftkings']);

    // Props are collected and not modelled. Edge Lab's schema enforces
    // that; this makes sure the page never implies otherwise.
    $this->actingAs(betUser('pro'))->get(route('props'))
        ->assertOk()
        ->assertSee('not modelled')
        ->assertDontSee('Confidence')
        ->assertDontSee('>Edge<', escape: false);
});

it('says why the props board is empty rather than implying coverage', function () {
    $this->actingAs(betUser('pro'))->get(route('props'))
        ->assertOk()
        ->assertSee('No prop quotes stored yet')
        ->assertSee('data licence does not permit');
});

it('keeps props behind pro', function () {
    $this->actingAs(betUser(null))->get(route('props'))
        ->assertRedirect(route('upgrade', ['feature' => 'props']));
});
