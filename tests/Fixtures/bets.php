<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;
use App\Models\VigilRead;
use App\Models\VlBoardEntry;
use App\Models\VlEvent;
use App\Models\VlModel;
use App\Models\VlOddsDisplay;
use App\Services\EdgeLab\Mirror;
use Illuminate\Support\Facades\DB;

/**
 * A customer, optionally on a tier.
 *
 * Shared rather than defined in one test file, because a helper that only
 * exists when its own file is loaded makes a single-file run fail for a
 * reason that has nothing to do with the code under test.
 */
function betUser(?string $tier = 'pro'): User
{
    $user = User::forceCreate([
        'name' => 'Sam',
        'email' => 'sam'.fake()->unique()->randomNumber(6).'@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
    ]);

    if ($tier !== null) {
        Subscription::create([
            'user_id' => $user->id, 'tier' => $tier, 'status' => 'active',
            'current_period_end' => now()->addMonth(), 'source' => 'test',
        ]);
    }

    return $user->refresh();
}

/** A board entry the product is actually allowed to sell. */
function provenEntry(array $overrides = []): VlBoardEntry
{
    return entryFor(array_merge([
        'recommendation' => 'bet',
        'market_verdict' => 'proven',
        'model_prob' => 0.62,
        'best_price_american' => -110,
    ], $overrides));
}

/**
 * Board picks for a slip, in the order they should come back.
 *
 * Shared for the reason given above: a helper that only exists when its
 * own test file loads breaks a single-file run for a reason unrelated to
 * the code under test, and both the builder tests and the saving tests
 * need a slip.
 *
 * @return array<int, int> board entry ids
 */
function slipOf(int $legs): array
{
    $ids = [];

    foreach (range(1, $legs) as $i) {
        $ids[] = provenEntry([
            'id' => $i,
            'event_id' => 100 + $i,
            'edge_lab_event_id' => 100 + $i,
            'selection' => 'Leg '.$i,
        ])->id;
    }

    return $ids;
}
/**
 * A proven board pick with a mirrored game, far enough from kickoff to
 * be publishable.
 *
 * Shared because the record and the alert tests both need it, and a
 * helper that only exists when its own file loads breaks a single-file
 * run for a reason unrelated to the code under test.
 */
function publishableBoard(array $overrides = [], int $hoursToKickoff = 4): VlBoardEntry
{
    $entry = provenEntry(array_merge(['edge_lab_event_id' => 900], $overrides));

    VlEvent::create([
        'edge_lab_id' => $entry->edge_lab_event_id,
        'sport' => $entry->sport,
        'starts_at' => now()->addHours($hoursToKickoff),
        'status' => 'scheduled',
        'home_name' => 'Kansas City Chiefs',
        'away_name' => 'Buffalo Bills',
        'home_abbreviation' => 'KC',
        'away_abbreviation' => 'BUF',
        'payload' => [],
        'digest' => 'e'.$entry->edge_lab_event_id,
        'observed_at' => now(),
    ]);

    return $entry;
}

/**
 * A mirrored game and a stored quote, for the cross-book and alert
 * tests.
 */
function crossGame(int $id = 77): VlEvent
{
    return VlEvent::create([
        'edge_lab_id' => $id, 'sport' => 'nfl', 'starts_at' => now()->addHours(3),
        'status' => 'scheduled', 'home_name' => 'Chiefs', 'away_name' => 'Bills',
        'home_abbreviation' => 'KC', 'away_abbreviation' => 'BUF',
        'payload' => [], 'digest' => 'e'.$id, 'observed_at' => now(),
    ]);
}

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

/** A board the size of a real NFL Sunday, with prices and reads. */
function fullBoard(int $games = 16, int $booksPerGame = 6): void
{
    $mirror = app(Mirror::class);

    VlModel::firstOrCreate(['edge_lab_id' => 1], [
        'sport' => 'nfl', 'market' => 'h2h',
        'model_type' => 'logistic', 'version' => 4, 'status' => 'active',
        'verdict' => 'proven', 'allows_picks' => true,
        'predictions' => 2_400, 'log_loss_improvement' => 0.031,
        'gate' => ['min_predictions' => 500],
        'trained_at' => now()->subWeek(), 'payload' => [],
        'digest' => 'm1', 'observed_at' => now(),
    ]);

    foreach (range(1, $games) as $g) {
        VlEvent::firstOrCreate(['edge_lab_id' => 2_000 + $g], [
            'sport' => 'nfl',
            'starts_at' => now()->addHours(3),
            'status' => 'scheduled',
            'home_name' => 'Home '.$g,
            'away_name' => 'Away '.$g,
            'home_abbreviation' => 'H'.$g,
            'away_abbreviation' => 'A'.$g,
            'payload' => [],
            'digest' => 'e'.$g,
            'observed_at' => now(),
        ]);

        /*
         * A pick per game, which is far more than the gate would ever
         * actually pass. The point is to load the page, not to be
         * realistic about how often a market is proven.
         */
        $row = boardRow($g, [
            'event_id' => 2_000 + $g,
            'recommendation' => 'bet',
            'market_verdict' => 'proven',
            'selection' => 'Home '.$g,
            'edge_at_price' => 0.05 - $g / 1000,
        ]);

        $mirror->write(VlBoardEntry::class, $row, [
            'edge_lab_id' => $g,
            'edge_lab_event_id' => 2_000 + $g,
            'sport' => 'nfl', 'market' => 'h2h', 'side' => 'home',
            'selection' => $row['selection'],
            'model_prob' => 0.58, 'fair_prob' => 0.56,
            'edge' => 0.02, 'edge_at_price' => $row['edge_at_price'],
            'best_book' => 'draftkings', 'best_book_title' => 'DraftKings',
            'best_price_american' => -140,
            'market_verdict' => 'proven', 'recommendation' => 'bet',
            'reason' => 'Proven market', 'observed_at' => now(),
        ]);

        $entry = VlBoardEntry::query()->latest('id')->firstOrFail();

        VigilRead::firstOrCreate([
            'subject_type' => 'board_entry', 'subject_id' => $entry->id,
            'prompt_version' => (int) config('ai.prompt_version'),
        ], [
            'body' => 'An explanation of the figures above.',
            'source' => 'fallback', 'fact_sheet' => [], 'created_at' => now(),
        ]);

        // Several books, several quotes each: what the movement panel and
        // the cross-book tools actually read.
        foreach (range(1, $booksPerGame) as $b) {
            foreach (range(1, 3) as $q) {
                VlOddsDisplay::firstOrCreate(['digest' => 'q'.$g.'-'.$b.'-'.$q], [
                    'edge_lab_event_id' => 2_000 + $g,
                    'book' => 'book'.$b, 'book_title' => 'Book '.$b,
                    'market' => 'h2h', 'side' => $q % 2 === 0 ? 'away' : 'home',
                    'price_american' => -110 - $q * 5,
                    'quoted_at' => now()->subMinutes($q * 10),
                    'observed_at' => now(),
                ]);
            }
        }
    }
}

/** How many queries a request runs. */
function queriesFor(callable $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $request();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}
