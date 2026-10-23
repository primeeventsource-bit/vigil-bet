<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;
use App\Models\VlBoardEntry;

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
