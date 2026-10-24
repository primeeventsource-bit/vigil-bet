<?php

declare(strict_types=1);

use App\Models\BankrollSetting;
use App\Services\Engine\Bankroll\StakeSizer;
use Database\Seeders\BankrollSettingsSeeder;

function range100to1000(array $overrides = []): BankrollSetting
{
    return BankrollSetting::create(array_merge([
        'starting_bankroll_cents' => 500000,
        'unit_size_cents' => 10000,
        'min_stake_cents' => 10000,     // $100
        'max_stake_cents' => 100000,    // $1,000
        'kelly_fraction' => 0.25,
        'max_stake_pct' => 20.0,
        'max_daily_exposure_pct' => 60.0,
        'daily_loss_limit_cents' => 50000,
        'weekly_loss_limit_cents' => 100000,
        'edge_threshold' => 0.03,
        'min_book_count' => 3,
        'require_proven_market' => true,
        'effective_from' => now()->subDay(),
        'created_at' => now(),
    ], $overrides));
}

it('reports what the stated maximum bet is as a share of bankroll', function () {
    $settings = range100to1000();

    expect($settings->impliedMaxStakePct(500000))->toBe(20.0)
        ->and($settings->impliedMaxStakePct(5000000))->toBe(2.0)
        ->and($settings->impliedMaxStakePct(2000000))->toBe(5.0);
});

it('names the risk band rather than silently clamping it', function () {
    $settings = range100to1000();

    expect($settings->riskBand(5000000))->toBe('conservative')   // $50,000
        ->and($settings->riskBand(2000000))->toBe('aggressive')  // $20,000
        ->and($settings->riskBand(1000000))->toBe('very aggressive')
        ->and($settings->riskBand(500000))->toBe('ruin risk');   // $5,000
});

it('works out the bankroll a stake range implies', function () {
    $settings = range100to1000();

    expect($settings->bankrollNeededForPct(2.0))->toBe(5000000)
        ->and($settings->bankrollNeededForPct(5.0))->toBe(2000000);
});

it('never exceeds the stated maximum however large the edge', function () {
    $sizer = app(StakeSizer::class);
    $settings = range100to1000();

    foreach ([0.60, 0.75, 0.90] as $probability) {
        $decision = $sizer->size($probability, -110, 50000000, $settings);

        expect($decision->stakeCents)->toBeLessThanOrEqual(100000);
    }
});

it('never stakes below the stated minimum', function () {
    $sizer = app(StakeSizer::class);
    $settings = range100to1000();

    foreach ([0.53, 0.535, 0.54, 0.55, 0.60] as $probability) {
        $decision = $sizer->size($probability, -110, 500000, $settings);

        expect($decision->stakeCents === 0 || $decision->stakeCents >= 10000)->toBeTrue();
    }
});

it('uses the whole range once the bankroll supports it', function () {
    $sizer = app(StakeSizer::class);
    $settings = range100to1000(['max_stake_pct' => 2.0]);

    // At $50,000 a small edge still clears the floor and a large one reaches
    // the ceiling, which is what makes the range mean anything.
    $small = $sizer->size(0.534, -110, 5000000, $settings);
    $large = $sizer->size(0.60, -110, 5000000, $settings);

    expect($small->isActionable)->toBeTrue()
        ->and($small->stakeCents)->toBeGreaterThanOrEqual(10000)
        ->and($large->stakeCents)->toBe(100000)
        ->and($large->cappedBy)->toBe('your maximum bet');
});

it('seeds a percentage guard that does not block the stated range', function () {
    // The seeder raises the guard to match what was actually decided, so a
    // bet I said I would make is never silently clamped to something else.
    $this->seed(BankrollSettingsSeeder::class);

    $settings = BankrollSetting::current();

    expect($settings)->not->toBeNull();

    $cap = (int) floor($settings->starting_bankroll_cents * ($settings->max_stake_pct / 100));

    expect($cap)->toBeGreaterThanOrEqual($settings->max_stake_cents);
});
