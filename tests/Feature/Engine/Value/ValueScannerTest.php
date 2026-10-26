<?php

declare(strict_types=1);

use App\Enums\JobStatus;
use App\Enums\Market;
use App\Enums\PassReason;
use App\Enums\Recommendation;
use App\Enums\Side;
use App\Enums\Sport;
use App\Enums\Verdict;
use App\Exceptions\AppendOnlyViolation;
use App\Jobs\Value\ScanValue;
use App\Modeling\Odds\OddsConverter;
use App\Models\BankrollLedgerEntry;
use App\Models\BankrollSetting;
use App\Models\Bet;
use App\Models\Book;
use App\Models\ConsensusPrice;
use App\Models\Event;
use App\Models\JobRun;
use App\Models\ModelVersion;
use App\Models\OddsSnapshot;
use App\Models\Prediction;
use App\Models\Team;
use App\Models\ValueBet;
use App\Services\Value\ValueScanner;

/**
 * A board with one game, priced at several books, and a model that has an
 * opinion about it. Each test bends one thing and checks the one decision
 * that should change.
 */
function board(float $modelProbability, Verdict $verdict = Verdict::Proven): Event
{
    $home = Team::create(['sport' => 'nfl', 'name' => 'Bills', 'display_name' => 'Buffalo Bills', 'slug' => 'bills']);
    $away = Team::create(['sport' => 'nfl', 'name' => 'Jets', 'display_name' => 'New York Jets', 'slug' => 'jets']);

    $event = Event::create([
        'sport' => 'nfl',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'starts_at' => now()->addHours(6),
        'status' => 'scheduled',
    ]);

    $version = ModelVersion::create([
        'sport' => 'nfl',
        'market' => Market::Moneyline->value,
        'model_type' => 'elo',
        'version' => 1,
        'params' => ['league' => []],
        'trained_at' => now()->subDay(),
        'trained_through_at' => now()->subDay(),
        'training_rows' => 500,
        'holdout_metrics' => ['log_loss' => 0.62, 'verdict' => $verdict->value],
        'status' => 'active',
    ]);

    foreach ([[Side::Home, $modelProbability], [Side::Away, 1.0 - $modelProbability]] as [$side, $prob]) {
        Prediction::create([
            'event_id' => $event->id,
            'model_version_id' => $version->id,
            'market' => Market::Moneyline->value,
            'side' => $side->value,
            'prob' => $prob,
            'fair_price_american' => OddsConverter::probabilityToAmerican($prob),
            'predicted_at' => now(),
            'event_starts_at' => $event->starts_at,
            'created_at' => now(),
        ]);
    }

    return $event;
}

/** Quote a side at a book, as of now unless told otherwise. */
function quoteAt(Event $event, string $bookKey, Side $side, int $price, bool $bettable = false, int $minutesAgo = 1): void
{
    $book = Book::firstOrCreate(
        ['key' => $bookKey],
        ['title' => mb_strtoupper($bookKey), 'is_tracked' => true, 'is_bettable' => $bettable]
    );

    OddsSnapshot::create([
        'event_id' => $event->id,
        'book_id' => $book->id,
        'market' => Market::Moneyline->value,
        'side' => $side->value,
        'price_american' => $price,
        'price_decimal' => OddsConverter::americanToDecimal($price),
        'pulled_at' => now()->subMinutes($minutesAgo),
        'created_at' => now(),
    ]);
}

/**
 * Three books quoting both sides, with the pricing book's price settable.
 * Three is the default minimum for trusting a fair price.
 */
function wholeBoard(Event $event, int $pricingHomePrice, int $pricingAwayPrice = -110): void
{
    quoteAt($event, 'hardrockbet', Side::Home, $pricingHomePrice, bettable: true);
    quoteAt($event, 'hardrockbet', Side::Away, $pricingAwayPrice, bettable: true);
    quoteAt($event, 'draftkings', Side::Home, -110, bettable: true);
    quoteAt($event, 'draftkings', Side::Away, -110);
    quoteAt($event, 'betmgm', Side::Home, -110);
    quoteAt($event, 'betmgm', Side::Away, -110);
}

beforeEach(function () {
    $this->scanner = app(ValueScanner::class);

    BankrollSetting::create([
        'starting_bankroll_cents' => 500000,
        'unit_size_cents' => 10000,
        'kelly_fraction' => 0.25,
        'max_stake_pct' => 2.0,
        'max_daily_exposure_pct' => 6.0,
        'daily_loss_limit_cents' => 100000,
        'weekly_loss_limit_cents' => 250000,
        'edge_threshold' => 0.03,
        'min_win_probability' => 0.55,
        'min_book_count' => 3,
        'require_proven_market' => true,
        'min_stake_cents' => 10000,
        'max_stake_cents' => 100000,
        'effective_from' => now()->subDay(),
        'created_at' => now(),
    ]);

    BankrollLedgerEntry::create([
        'entry_type' => 'deposit', 'amount_cents' => 500000,
        'balance_after_cents' => 500000, 'occurred_at' => now(), 'created_at' => now(),
    ]);
});

function homePick(): ?ValueBet
{
    return ValueBet::where('side', Side::Home)->first();
}

it('flags a bet the model likes at a price that pays for it', function () {
    // Model says 65 percent; the pricing book pays -110, which breaks even
    // at 52.4. That is twelve points of edge at the price on offer.
    $event = board(0.65);
    wholeBoard($event, -110);

    $scan = $this->scanner->scan(Sport::Nfl);

    $pick = homePick();

    expect($scan->candidates)->toBe(2)
        ->and($scan->picks)->toBe(1)
        ->and($pick->recommendation)->toBe(Recommendation::Bet)
        ->and($pick->pass_reason)->toBeNull()
        ->and($pick->suggested_stake_cents)->toBeGreaterThan(0)
        ->and($pick->reason)->toContain('over the break-even rate');
});

it('keeps the two edges apart', function () {
    $event = board(0.65);
    wholeBoard($event, -110);

    $this->scanner->scan(Sport::Nfl);

    $pick = homePick();

    // edge is against the no-vig fair price, which is 50 percent here, so
    // 15 points. edge_at_price is against the break-even rate of -110,
    // which is 52.4, so about 12.6. Collapsing them would overstate the bet.
    expect($pick->fair_prob)->toEqualWithDelta(0.5, 0.01)
        ->and($pick->edge)->toEqualWithDelta(0.15, 0.01)
        ->and($pick->break_even_win_rate)->toEqualWithDelta(0.5238, 0.001)
        ->and($pick->edge_at_price)->toEqualWithDelta(0.1262, 0.001);
});

it('records every candidate, not just the ones it liked', function () {
    // The away side is a 35 percent shot at -110 and is hopeless. It is
    // still written down, with a reason, because a board that hides what it
    // rejected cannot be argued with.
    $event = board(0.65);
    wholeBoard($event, -110);

    $this->scanner->scan(Sport::Nfl);

    $away = ValueBet::where('side', Side::Away)->first();

    expect(ValueBet::count())->toBe(2)
        ->and($away->recommendation)->toBe(Recommendation::Pass)
        ->and($away->pass_reason)->toBe(PassReason::NegativeEdge)
        ->and($away->reason)->not->toBeEmpty()
        // The numbers behind the decision are kept even on a pass.
        ->and($away->edge_at_price)->toBeLessThan(0.0);
});

it('passes when the model has not proven itself here', function () {
    $event = board(0.65, Verdict::Unproven);
    wholeBoard($event, -110);

    $scan = $this->scanner->scan(Sport::Nfl);

    expect($scan->picks)->toBe(0)
        ->and(homePick()->pass_reason)->toBe(PassReason::MarketUnproven)
        ->and(homePick()->market_verdict)->toBe(Verdict::Unproven)
        // The edge is still computed and stored; it just does not get to pick.
        ->and(homePick()->edge_at_price)->toBeGreaterThan(0.0);
});

it('bets an unproven market when the owner turns the gate off', function () {
    // Settings are append-only: a change is a newer row, and current()
    // reads the newest one that has taken effect.
    $relaxed = BankrollSetting::current()->replicate();
    $relaxed->require_proven_market = false;
    $relaxed->effective_from = now();
    $relaxed->created_at = now();
    $relaxed->save();

    /*
     * 0.80 rather than 0.65, because an unproven market is now shrunk
     * toward the book before any edge is taken: 0.65 comes back as 0.538
     * against a break-even of 0.524, which is a 1.4 point edge and under
     * the threshold. The gate being off is still what this test is about
     * - it just needs a disagreement big enough to survive the shrink.
     */
    $event = board(0.80, Verdict::Unproven);
    wholeBoard($event, -110);

    expect($this->scanner->scan(Sport::Nfl)->picks)->toBe(1);
});

it('passes a real edge that is too small to be worth it', function () {
    // 54 percent against a break-even of 52.4 is a genuine edge of 1.6
    // points, under the 3 point threshold.
    $event = board(0.54);
    wholeBoard($event, -110);

    $this->scanner->scan(Sport::Nfl);

    expect(homePick()->pass_reason)->toBe(PassReason::BelowThreshold)
        ->and(homePick()->edge_at_price)->toBeGreaterThan(0.0);
});

it('says the price is too short here when the bet is good elsewhere', function () {
    // The model likes this at 60 percent. The best board price of +120
    // clears the threshold comfortably; the pricing book's -150 does not.
    // That is a different fact from "there is no edge".
    $event = board(0.60);
    quoteAt($event, 'hardrockbet', Side::Home, -140, bettable: true);
    quoteAt($event, 'hardrockbet', Side::Away, 120, bettable: true);
    quoteAt($event, 'draftkings', Side::Home, 120);
    quoteAt($event, 'draftkings', Side::Away, -140);
    quoteAt($event, 'betmgm', Side::Home, 110);
    quoteAt($event, 'betmgm', Side::Away, -130);

    $this->scanner->scan(Sport::Nfl);

    expect(homePick()->pass_reason)->toBe(PassReason::PriceTooShortHere)
        ->and(homePick()->better_elsewhere)->toBeTrue()
        ->and(homePick()->reason)->toContain('not paying enough')
        // Never a recommendation to bet where there is no account.
        ->and(homePick()->recommendation)->toBe(Recommendation::Pass);
});

it('passes a profitable bet that wins less often than asked', function () {
    // An underdog with a real edge at a long price. This is a display
    // preference, not a profitability test, and the reason says so.
    $event = board(0.40);
    wholeBoard($event, 250, -110);

    $this->scanner->scan(Sport::Nfl);

    expect(homePick()->edge_at_price)->toBeGreaterThan(0.03)
        ->and(homePick()->pass_reason)->toBe(PassReason::BelowMinWinProbability)
        ->and(homePick()->reason)->toContain('profitable');
});

it('passes when too few books are quoting to trust the fair price', function () {
    $event = board(0.65);
    quoteAt($event, 'hardrockbet', Side::Home, -110, bettable: true);
    quoteAt($event, 'hardrockbet', Side::Away, -110, bettable: true);

    $this->scanner->scan(Sport::Nfl);

    expect(homePick()->pass_reason)->toBe(PassReason::TooFewBooks)
        ->and(homePick()->book_count)->toBe(1);
});

it('passes when the pricing book is not quoting at all', function () {
    $event = board(0.65);
    quoteAt($event, 'draftkings', Side::Home, -110);
    quoteAt($event, 'draftkings', Side::Away, -110);
    quoteAt($event, 'betmgm', Side::Home, -110);
    quoteAt($event, 'betmgm', Side::Away, -110);
    quoteAt($event, 'fanduel', Side::Home, -110);
    quoteAt($event, 'fanduel', Side::Away, -110);

    $this->scanner->scan(Sport::Nfl);

    expect(homePick()->pass_reason)->toBe(PassReason::NoPrice)
        ->and(homePick()->best_price_american)->toBeNull();
});

it('tells a stale board apart from an empty one', function () {
    // Quoted, but hours ago. The shopper will not act on it and neither
    // will this - but a stale board means the pull cadence needs looking
    // at, which an empty one does not.
    $event = board(0.65);

    // Quoted six hours ago rather than quoted and then aged: the table
    // is append-only, and on Postgres the trigger says so.
    $this->travelTo(now()->subHours(6), fn () => wholeBoard($event, -110));

    $this->scanner->scan(Sport::Nfl);

    expect(homePick()->pass_reason)->toBe(PassReason::StaleQuote);
});

it('stops picking entirely when a loss limit is locked', function () {
    $event = board(0.65);
    wholeBoard($event, -110);

    // Blow through the daily loss limit.
    BankrollLedgerEntry::create([
        'entry_type' => 'bet_payout', 'amount_cents' => -200000,
        'balance_after_cents' => 300000, 'occurred_at' => now(), 'created_at' => now(),
    ]);
    Bet::create([
        'event_id' => $event->id,
        'market' => Market::Moneyline->value,
        'side' => Side::Home->value,
        'price_american' => -110,
        'price_decimal' => 1.9091,
        'stake_cents' => 200000,
        'placed_at' => now(),
        'status' => 'lost',
        'settled_at' => now(),
        'pnl_cents' => -200000,
    ]);

    $scan = $this->scanner->scan(Sport::Nfl);

    expect($scan->picks)->toBe(0)
        ->and(homePick()->pass_reason)->toBe(PassReason::LockoutActive);
});

it('states the price cutoff and the model fair price', function () {
    $event = board(0.65);
    wholeBoard($event, -110);

    $this->scanner->scan(Sport::Nfl);

    $pick = homePick();

    // break_even is the model's own fair price; min_acceptable is the worst
    // price that still clears the threshold. If the line moves past it the
    // bet is off.
    expect($pick->break_even_price_american)->toBe(OddsConverter::probabilityToAmerican(0.65))
        ->and($pick->min_acceptable_price_american)
        ->toBe(OddsConverter::minimumAcceptableAmerican(0.65, 0.03));
});

it('stores the whole board it shopped', function () {
    $event = board(0.65);
    wholeBoard($event, -110);

    $this->scanner->scan(Sport::Nfl);

    $pick = homePick();

    expect($pick->line_shopped)->toHaveKeys(['hardrockbet', 'draftkings', 'betmgm'])
        ->and($pick->market_best_price_american)->not->toBeNull()
        ->and($pick->book_count)->toBe(3);
});

it('ranks picks two ways and stores both', function () {
    $first = board(0.65);
    wholeBoard($first, -110);

    // A second game: a smaller favourite at a much better price, so it
    // leads on value while trailing on likelihood.
    $home = Team::create(['sport' => 'nfl', 'name' => 'Rams', 'display_name' => 'LA Rams', 'slug' => 'rams']);
    $away = Team::create(['sport' => 'nfl', 'name' => 'Niners', 'display_name' => 'SF 49ers', 'slug' => 'niners']);
    $second = Event::create([
        'sport' => 'nfl', 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'starts_at' => now()->addHours(8), 'status' => 'scheduled',
    ]);
    $version = ModelVersion::first();

    foreach ([[Side::Home, 0.58], [Side::Away, 0.42]] as [$side, $prob]) {
        Prediction::create([
            'event_id' => $second->id, 'model_version_id' => $version->id,
            'market' => Market::Moneyline->value, 'side' => $side->value, 'prob' => $prob,
            'fair_price_american' => OddsConverter::probabilityToAmerican($prob),
            'predicted_at' => now(), 'event_starts_at' => $second->starts_at, 'created_at' => now(),
        ]);
    }

    wholeBoard($second, 150, -170);

    $this->scanner->scan(Sport::Nfl);

    $likely = ValueBet::where('event_id', $first->id)->where('side', Side::Home)->first();
    $valuable = ValueBet::where('event_id', $second->id)->where('side', Side::Home)->first();

    // Ordering is not selection: both cleared the test first.
    expect($likely->probability_rank)->toBe(1)
        ->and($valuable->probability_rank)->toBe(2)
        ->and($valuable->value_rank)->toBe(1)
        ->and($likely->value_rank)->toBe(2);
});

it('leaves passes unranked', function () {
    $event = board(0.54);
    wholeBoard($event, -110);

    $this->scanner->scan(Sport::Nfl);

    expect(homePick()->probability_rank)->toBeNull()
        ->and(homePick()->value_rank)->toBeNull();
});

it('records the fair price it computed, not just the decision', function () {
    $event = board(0.65);
    wholeBoard($event, -110);

    $this->scanner->scan(Sport::Nfl);

    $consensus = ConsensusPrice::where('side', Side::Home->value)->first();

    // Append-only history of what the market thought, so it is possible to
    // ask later whether the model was wrong or the market moved.
    expect($consensus)->not->toBeNull()
        ->and($consensus->fair_prob)->toEqualWithDelta(0.5, 0.01)
        ->and($consensus->book_count)->toBe(3)
        ->and($consensus->implied_sum)->toBeGreaterThan(1.0);
});

it('never rewrites a scanned candidate', function () {
    $event = board(0.65);
    wholeBoard($event, -110);

    $this->scanner->scan(Sport::Nfl);

    expect(fn () => homePick()->update(['model_prob' => 0.99]))
        ->toThrow(AppendOnlyViolation::class);
});

it('writes a fresh scan each time rather than moving the last one', function () {
    $event = board(0.65);
    wholeBoard($event, -110);

    $first = $this->scanner->scan(Sport::Nfl);
    $second = $this->scanner->scan(Sport::Nfl);

    // The Today screen renders one scan, so a board cannot shift while it
    // is being read, and what was flagged when survives.
    expect($second->id)->not->toBe($first->id)
        ->and(ValueBet::where('value_scan_id', $first->id)->count())->toBe(2)
        ->and(ValueBet::where('value_scan_id', $second->id)->count())->toBe(2);
});

it('scans only the newest prediction for a game', function () {
    $event = board(0.65);
    wholeBoard($event, -110);

    // A retrained model activates and predicts afresh; the old rows stand.
    $newer = ModelVersion::create([
        'sport' => 'nfl', 'market' => Market::Moneyline->value, 'model_type' => 'elo', 'version' => 2,
        'params' => ['league' => []], 'trained_at' => now(), 'trained_through_at' => now(),
        'training_rows' => 600, 'holdout_metrics' => ['verdict' => Verdict::Proven->value], 'status' => 'active',
    ]);

    Prediction::create([
        'event_id' => $event->id, 'model_version_id' => $newer->id,
        'market' => Market::Moneyline->value, 'side' => Side::Home->value, 'prob' => 0.70,
        'fair_price_american' => OddsConverter::probabilityToAmerican(0.70),
        'predicted_at' => now()->addSecond(), 'event_starts_at' => $event->starts_at, 'created_at' => now(),
    ]);

    $this->scanner->scan(Sport::Nfl);

    expect(ValueBet::where('side', Side::Home)->count())->toBe(1)
        ->and(homePick()->model_version_id)->toBe($newer->id)
        ->and(homePick()->model_prob)->toEqualWithDelta(0.70, 1e-6);
});

it('ignores games that have already started', function () {
    $event = board(0.65);
    wholeBoard($event, -110);
    $event->forceFill(['starts_at' => now()->subHour()])->save();

    expect($this->scanner->scan(Sport::Nfl)->candidates)->toBe(0);
});

it('runs through the queued job and reports on the jobs screen', function () {
    $event = board(0.65);
    wholeBoard($event, -110);

    dispatch_sync(new ScanValue(Sport::Nfl));

    $run = JobRun::where('job_key', 'value:scan')->latest('id')->first();

    expect($run->status)->toBe(JobStatus::Success)
        ->and($run->records_out)->toBe(1)
        ->and($run->context['candidates'])->toBe(2);
});

it('scans an empty board without complaint', function () {
    $scan = $this->scanner->scan(Sport::Nfl);

    expect($scan->candidates)->toBe(0)
        ->and($scan->picks)->toBe(0);
});
