<?php

declare(strict_types=1);

use App\Models\BankrollSetting;

function boundsSettings(array $overrides = []): BankrollSetting
{
    return BankrollSetting::create(array_merge([
        'starting_bankroll_cents' => 500000,
        'unit_size_cents' => 10000,
        'min_stake_cents' => 10000,
        'max_stake_cents' => 100000,
        'kelly_fraction' => 0.25,
        'max_stake_pct' => 20.0,
        'max_daily_exposure_pct' => 6.0,
        'daily_loss_limit_cents' => 50000,
        'weekly_loss_limit_cents' => 100000,
        'edge_threshold' => 0.03,
        'min_book_count' => 3,
        'require_proven_market' => true,
        'effective_from' => now()->subDay(),
        'created_at' => now(),
    ], $overrides));
}

it('takes both per-bet ceilings off', function () {
    $original = boundsSettings();

    $this->artisan('edge:bankroll:stakes', ['--max' => 'off', '--pct' => 'off', '--force' => true])
        ->assertSuccessful();

    $current = BankrollSetting::current();

    expect($current->id)->not->toBe($original->id)
        ->and($current->max_stake_cents)->toBeNull()
        ->and($current->max_stake_pct)->toBeNull()
        // The floor was not asked about, so it is carried forward.
        ->and($current->min_stake_cents)->toBe(10000);
});

it('says what is left holding a bet down', function () {
    // The part that is easy to miss: no per-bet ceiling is not no limit.
    boundsSettings();

    $this->artisan('edge:bankroll:stakes', ['--max' => 'off', '--pct' => 'off', '--force' => true])
        // One assertion, not two: both facts are on the same output line,
        // and expectsOutputToContain consumes a line per call.
        ->expectsOutputToContain('No per-bet ceiling at all. A stake will be fractional Kelly, or whatever the 6% daily exposure cap has left')
        ->assertSuccessful();
});

it('appends rather than editing', function () {
    $original = boundsSettings();

    $this->artisan('edge:bankroll:stakes', ['--min' => '250', '--force' => true])->assertSuccessful();

    expect(BankrollSetting::count())->toBe(2)
        ->and($original->fresh()->min_stake_cents)->toBe(10000);
});

it('carries forward the limits it was not asked about', function () {
    boundsSettings(['daily_loss_limit_cents' => 42000]);

    $this->artisan('edge:bankroll:stakes', ['--pct' => 'off', '--force' => true])->assertSuccessful();

    expect(BankrollSetting::current()->daily_loss_limit_cents)->toBe(42000);
});

it('refuses a floor above the ceiling', function () {
    boundsSettings();

    $this->artisan('edge:bankroll:stakes', ['--min' => '900', '--max' => '100', '--force' => true])
        ->expectsOutputToContain('no stake could fit')
        ->assertFailed();

    expect(BankrollSetting::count())->toBe(1);
});

it('refuses to guess when given nothing to change', function () {
    boundsSettings();

    $this->artisan('edge:bankroll:stakes', ['--force' => true])->assertFailed();

    expect(BankrollSetting::count())->toBe(1);
});

it('writes nothing when the confirmation is declined', function () {
    boundsSettings();

    $this->artisan('edge:bankroll:stakes', ['--max' => 'off'])
        ->expectsConfirmation('Save this as a new settings version?', 'no')
        ->assertSuccessful();

    expect(BankrollSetting::count())->toBe(1);
});

it('says there is nothing to revise before anything is seeded', function () {
    $this->artisan('edge:bankroll:stakes', ['--max' => 'off', '--force' => true])->assertFailed();
});

it('can put a cap back', function () {
    boundsSettings();

    $this->artisan('edge:bankroll:stakes', ['--pct' => 'off', '--force' => true])->assertSuccessful();
    $this->artisan('edge:bankroll:stakes', ['--pct' => '5', '--force' => true])->assertSuccessful();

    expect((float) BankrollSetting::current()->max_stake_pct)->toBe(5.0);
});
