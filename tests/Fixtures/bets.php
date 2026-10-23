<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;
use App\Models\VlBoardEntry;
use App\Models\VlEvent;
use App\Models\VlOddsDisplay;

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
