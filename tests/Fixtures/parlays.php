<?php

declare(strict_types=1);

/*
 * Shared parlay fixtures.
 *
 * These live here rather than in a test file because two suites build the
 * same legs, and a helper that exists only when some other file happens to
 * have been loaded makes running one file on its own fail.
 */

use App\Enums\Market;
use App\Enums\Recommendation;
use App\Enums\Side;
use App\Enums\Verdict;
use App\Modeling\Odds\OddsConverter;
use App\Models\BankrollSetting;
use App\Models\Book;
use App\Models\Event;
use App\Models\Team;
use App\Models\ValueBet;
use App\Models\ValueScan;
use Illuminate\Support\Str;

function parlayBankroll(): BankrollSetting
{
    return BankrollSetting::create([
        'starting_bankroll_cents' => 500000,
        'unit_size_cents' => 10000,
        'kelly_fraction' => 0.25,
        'max_stake_pct' => 2.0,
        'max_daily_exposure_pct' => 6.0,
        'min_stake_cents' => 1000,
        'max_stake_cents' => 100000,
        'daily_loss_limit_cents' => 50000,
        'weekly_loss_limit_cents' => 100000,
        'edge_threshold' => 0.03,
        'effective_from' => now()->subMonth(),
    ]);
}

function parlayScan(): ValueScan
{
    return ValueScan::create([
        'scanned_at' => now()->subMinutes(5),
        'settings' => ['edge_threshold' => 0.03],
        'bankroll_cents' => 500000,
        'candidates' => 0,
        'picks' => 0,
        'created_at' => now(),
    ]);
}

/**
 * A judged leg: an event, and the scan row that priced it.
 *
 * The fair probability sits below the break-even rate at the price, which
 * is the normal case and the only one where the book has a margin at all.
 *
 * @return array{event_id:int, market:Market, side:Side}
 */
function parlayLeg(
    ValueScan $scan,
    float $modelProb = 0.60,
    float $fairProb = 0.50,
    int $price = -110,
    Verdict $verdict = Verdict::Proven,
    ?Event $event = null,
    Market $market = Market::Moneyline,
    Side $side = Side::Home,
): array {
    if ($event === null) {
        $name = Str::random(8);
        $home = Team::create(['sport' => 'nfl', 'name' => "H {$name}", 'display_name' => "Home {$name}", 'slug' => Str::slug("h-{$name}")]);
        $away = Team::create(['sport' => 'nfl', 'name' => "A {$name}", 'display_name' => "Away {$name}", 'slug' => Str::slug("a-{$name}")]);

        $event = Event::create([
            'sport' => 'nfl',
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'starts_at' => now()->addHours(6),
            'status' => 'scheduled',
        ]);
    }

    $book = Book::query()->firstOrCreate(
        ['key' => 'hardrockbet'],
        ['title' => 'Hard Rock Bet', 'is_tracked' => true, 'is_bettable' => true]
    );

    ValueBet::create([
        'value_scan_id' => $scan->id,
        'event_id' => $event->id,
        'sport' => 'nfl',
        'market' => $market->value,
        'side' => $side->value,
        'model_prob' => $modelProb,
        'fair_prob' => $fairProb,
        'break_even_win_rate' => OddsConverter::americanToProbability($price),
        'edge' => $modelProb - $fairProb,
        'edge_at_price' => $modelProb - OddsConverter::americanToProbability($price),
        'recommendation' => Recommendation::Pass->value,
        'best_book_id' => $book->id,
        'best_price_american' => $price,
        'market_verdict' => $verdict->value,
        'threshold_used' => 0.03,
        'flagged_at' => now()->subMinutes(5),
        'created_at' => now(),
    ]);

    return ['event_id' => $event->id, 'market' => $market, 'side' => $side];
}
