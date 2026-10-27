<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;
use App\Services\Entitlements\Entitlements;

/*
|--------------------------------------------------------------------------
| Elite sells something, and this is the proof
|--------------------------------------------------------------------------
|
| Pro already granted every one of the fifteen feature keys that existed
| before this change, so a tier priced above it had to either unlock
| something real or be a higher price for the same product. The second
| would have been a lie told on a pricing page, which is the one place
| the brief is least forgiving about.
|
| So: the two grants Elite adds have pages behind them, the pages are
| closed to Pro, and the bullets on the pricing card correspond to those
| pages rather than to adjectives.
|
*/

function tiered(string $tier): User
{
    $user = User::forceCreate([
        'name' => 'Tier Holder',
        'email' => 'tier'.fake()->unique()->randomNumber(8).'@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
    ]);

    Subscription::create([
        'user_id' => $user->id,
        'tier' => $tier,
        'status' => 'active',
        'current_period_end' => now()->addMonth(),
        'source' => 'test',
    ]);

    return $user->refresh();
}

it('grants Elite exactly two things Pro does not', function () {
    $entitlements = app(Entitlements::class);

    $pro = (array) config('plans.tiers.pro.grants');
    $elite = (array) config('plans.tiers.elite.grants');

    $extra = array_values(array_diff($elite, $pro));

    expect($extra)->toEqualCanonicalizing(['playeriq', 'props.projected'])
        // And nothing Pro has was quietly taken away to manufacture a gap.
        ->and(array_diff($pro, $elite))->toBe([]);
});

it('puts a real page behind each of them', function (string $route, string $feature) {
    // A grant with no page behind it is a bullet point.
    $this->actingAs(tiered('elite'))->get(route($route))->assertOk();

    $this->actingAs(tiered('pro'))->get(route($route))
        ->assertRedirect(route('upgrade', ['feature' => $feature]));
})->with([
    ['player-iq', 'playeriq'],
    ['prop-projections', 'props.projected'],
]);

it('charges what the pricing page says', function () {
    expect(config('plans.tiers.core.monthly_cents'))->toBe(2999)
        ->and(config('plans.tiers.pro.monthly_cents'))->toBe(5999)
        ->and(config('plans.tiers.elite.monthly_cents'))->toBe(9999);
});

it('gives Elite the Read allowance its own bullet promises', function () {
    /*
     * "The highest Read allowance" is a sentence on the pricing card.
     * A tier that claimed it and was capped at the tier below would be
     * the product contradicting its own checkout.
     */
    $limits = (array) config('ai.rate_limits');

    expect($limits['elite'])->toBeGreaterThan($limits['pro'])
        ->and($limits['pro'])->toBeGreaterThan($limits['core']);
});

it('does not promise a feature with no key behind it', function () {
    /*
     * Every bullet on the Elite card has to correspond to something the
     * product enforces. These are the two that name a capability rather
     * than restate Pro, and both are checked above - this asserts the
     * card still says them, so removing a page without removing its
     * promise fails here.
     */
    $features = (array) config('plans.tiers.elite.features');
    $text = mb_strtolower(implode(' ', $features));

    expect($text)->toContain('player iq')
        ->and($text)->toContain('projected player props');
});
