<?php

declare(strict_types=1);

use App\Enums\PassReason;
use App\Models\BankrollSetting;
use App\Services\Engine\Bankroll\StakeSizer;

function settings(array $overrides = []): BankrollSetting
{
    return BankrollSetting::create(array_merge([
        'starting_bankroll_cents' => 500000,   // $5,000
        'unit_size_cents' => 10000,            // $100
        'min_stake_cents' => 10000,            // $100
        'max_stake_cents' => 100000,           // $1,000
        'kelly_fraction' => 0.25,
        'max_stake_pct' => 2.0,
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

beforeEach(fn () => $this->sizer = app(StakeSizer::class));

it('caps a big edge at the per-bet percentage of bankroll', function () {
    // Quarter Kelly on a huge edge wants far more than 2% of $5,000.
    $decision = $this->sizer->size(0.70, 100, 500000, settings());

    expect($decision->isActionable)->toBeTrue()
        ->and($decision->stakeCents)->toBe(10000)       // $100 = 2% of $5,000
        ->and($decision->cappedBy)->toBe('max stake per bet')
        ->and($decision->uncappedKellyCents)->toBeGreaterThan(10000);
});

it('respects an absolute ceiling on a single bet', function () {
    // $200,000 bankroll: 2% is $4,000, but my ceiling is $1,000.
    $decision = $this->sizer->size(0.70, 100, 20000000, settings());

    expect($decision->stakeCents)->toBe(100000)
        ->and($decision->cappedBy)->toBe('your maximum bet')
        ->and($decision->units)->toBe(10.0);
});

it('skips the bet when correct sizing lands under my minimum', function () {
    // A real but thin edge on a $5,000 bankroll sizes to about $29. The honest
    // answer is to pass, never to round the stake up to the floor.
    $decision = $this->sizer->size(0.535, -110, 500000, settings());

    expect($decision->isActionable)->toBeFalse()
        ->and($decision->passReason)->toBe(PassReason::BelowMinStake)
        ->and($decision->stakeCents)->toBe(0)
        ->and($decision->explanation)->toContain('below your $100.00 minimum');
});

it('stakes nothing when the price gives the model no edge', function () {
    $decision = $this->sizer->size(0.50, -110, 500000, settings());

    expect($decision->isActionable)->toBeFalse()
        ->and($decision->passReason)->toBe(PassReason::NegativeEdge);
});

it('sizes from Kelly when no cap binds', function () {
    // $50,000 bankroll, 2% cap is $1,000, ceiling is $1,000, and quarter
    // Kelly on this edge lands under both.
    $decision = $this->sizer->size(0.56, -110, 5000000, settings());

    expect($decision->isActionable)->toBeTrue()
        ->and($decision->cappedBy)->toBeNull()
        ->and($decision->stakeCents)->toBeLessThan(100000)
        ->and($decision->stakeCents)->toBeGreaterThanOrEqual(10000)
        ->and($decision->explanation)->toContain('quarter Kelly');
});

it('expresses the stake in units of my bet size', function () {
    $decision = $this->sizer->size(0.70, 100, 20000000, settings());

    // $1,000 at a $100 unit is ten units.
    expect($decision->units)->toBe(10.0);
});

it('refuses to size without saved settings', function () {
    $decision = $this->sizer->size(0.60, -110, 500000);

    expect($decision->isActionable)->toBeFalse();
});

it('reads the newest effective settings row', function () {
    settings();
    settings(['max_stake_cents' => 25000, 'effective_from' => now()]);

    $decision = $this->sizer->size(0.70, 100, 20000000);

    expect($decision->stakeCents)->toBe(25000);
});

it('says the bankroll is missing rather than blaming the model', function () {
    // With nothing saved there is no bankroll to size against. Reporting
    // NoModel here reads as "no trained model covers this market", which
    // sends the reader hunting for a modelling problem that does not exist.
    $decision = $this->sizer->size(0.65, -110, 500000);

    expect($decision->isActionable)->toBeFalse()
        ->and($decision->passReason)->toBe(PassReason::NoBankrollSettings)
        ->and($decision->passReason->label())->toBe('No bankroll set up')
        ->and($decision->passReason->detail())->toContain('bankroll settings');
});
