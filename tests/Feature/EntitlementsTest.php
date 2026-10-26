<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\EntitlementOverride;
use App\Models\Subscription;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\VlBoardEntry;
use App\Services\EdgeLab\Mirror;
use App\Services\Entitlements\Entitlements;
use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

function entitlements(): Entitlements
{
    return app(Entitlements::class);
}

function userOn(?string $tier, array $subscription = []): User
{
    $user = User::forceCreate([
        'name' => 'Sam',
        'email' => 'sam'.fake()->unique()->randomNumber(6).'@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
    ]);

    if ($tier !== null) {
        Subscription::create($subscription + [
            'user_id' => $user->id,
            'tier' => $tier,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
            'source' => 'test',
        ]);
    }

    return $user->refresh();
}

/**
 * Every route the application gates, read from the router rather than
 * listed here.
 *
 * Written this way deliberately: a hand-kept list is a list somebody
 * forgets to extend, and the whole point of the §13 check is that it
 * covers the route added next month by someone who never read this file.
 *
 * @return array<int, array{0: string, 1: string}> route name, feature
 */
function gatedRoutes(): array
{
    $gated = [];

    foreach (Route::getRoutes() as $route) {
        // Pages only. A gated POST is covered by the form test that
        // exercises it; fetching one with GET returns 405 and proves
        // nothing about entitlements.
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'entitled:')) {
                $gated[] = [$route->getName(), explode(':', $middleware, 2)[1]];
            }
        }
    }

    return $gated;
}

it('gates more than one route, so the checks below are actually checking something', function () {
    expect(gatedRoutes())->not->toBeEmpty();
});

it('treats a signed-out visitor as free rather than as nothing', function () {
    expect(entitlements()->tier(null))->toBe('free')
        ->and(entitlements()->can(null, 'board.today'))->toBeTrue()
        ->and(entitlements()->can(null, 'board.full'))->toBeFalse();
});

it('gives a user with no subscription the free grants and nothing more', function () {
    $user = userOn(null);

    expect(entitlements()->tier($user))->toBe('free')
        ->and(entitlements()->can($user, 'parlay.grade'))->toBeTrue()
        ->and(entitlements()->can($user, 'board.full'))->toBeFalse()
        ->and(entitlements()->cannot($user, 'props'))->toBeTrue();
});

it('gives a pro subscriber every feature any tier grants', function () {
    $user = userOn('pro');

    foreach (entitlements()->allFeatures() as $feature) {
        expect(entitlements()->can($user, $feature))->toBeTrue();
    }
});

it('treats a tier that is not in the config as free', function () {
    $user = userOn('platinum-unicorn');

    expect(entitlements()->tier($user))->toBe('free')
        ->and(entitlements()->can($user, 'board.full'))->toBeFalse();
});

/*
 * §13's acceptance check, and the reason the middleware exists: the nav
 * hiding a feature is a courtesy, this is the part that holds when a
 * customer edits the URL.
 */
it('does not let a free user reach a gated page by typing its address', function () {
    $user = userOn(null);
    $checked = 0;

    foreach (gatedRoutes() as [$name, $feature]) {
        // Some gated routes are gated against a feature Free does have
        // - parlays, so the daily cap has somewhere to live. Those are
        // not paywalls and must not be asserted as if they were.
        if (entitlements()->can($user, $feature)) {
            continue;
        }

        $this->actingAs($user)->get(route($name))
            ->assertRedirect(route('upgrade', ['feature' => $feature]));

        $checked++;
    }

    expect($checked)->toBeGreaterThan(0);
});

it('returns 403 and no data when a free user asks for gated routes as json', function () {
    $user = userOn(null);

    foreach (gatedRoutes() as [$name, $feature]) {
        if (entitlements()->can($user, $feature)) {
            continue;
        }

        $response = $this->actingAs($user)
            ->getJson(route($name))
            ->assertForbidden()
            ->assertJsonPath('error', 'upgrade_required')
            ->assertJsonPath('feature', $feature);

        // Nothing but the refusal: no partially rendered page, no row.
        expect(array_keys($response->json()))
            ->toEqualCanonicalizing(['error', 'feature', 'required_tier']);
    }
});

it('lets a pro subscriber into every gated page', function () {
    $user = userOn('pro');

    foreach (gatedRoutes() as [$name, $feature]) {
        $this->actingAs($user)->get(route($name))->assertOk();
    }
});

it('refuses a gated page to a signed-out visitor at the login, not the upgrade page', function () {
    // The order matters: an anonymous request must not be told it needs
    // a bigger plan when what it needs is an account.
    foreach (gatedRoutes() as [$name, $feature]) {
        $this->get(route($name))->assertRedirect(route('login'));
    }
});

it('never gates the upgrade page itself', function () {
    $this->actingAs(userOn(null))->get(route('upgrade', ['feature' => 'board.full']))->assertOk();
});

/*
|--------------------------------------------------------------------------
| Overrides
|--------------------------------------------------------------------------
*/

it('lets an override grant a feature the plan does not include', function () {
    $user = userOn(null);

    EntitlementOverride::create([
        'user_id' => $user->id,
        'feature' => 'board.full',
        'granted' => true,
        'reason' => 'Billing dispute, comped while it is sorted out',
        'granted_by' => $user->id,
    ]);

    expect(entitlements()->can($user, 'board.full'))->toBeTrue();

    $this->actingAs($user)->get(route('picks'))->assertOk();
});

it('lets an override withdraw a feature the plan does include', function () {
    $user = userOn('pro');

    EntitlementOverride::create([
        'user_id' => $user->id,
        'feature' => 'board.full',
        'granted' => false,
        'reason' => 'Abuse of the line shopping endpoint',
        'granted_by' => $user->id,
    ]);

    expect(entitlements()->can($user, 'board.full'))->toBeFalse();

    $this->actingAs($user)->get(route('picks'))
        ->assertRedirect(route('upgrade', ['feature' => 'board.full']));
});

it('ignores an override that has expired', function () {
    $user = userOn(null);

    EntitlementOverride::create([
        'user_id' => $user->id,
        'feature' => 'board.full',
        'granted' => true,
        'reason' => 'Two week trial of the full board',
        'granted_by' => $user->id,
        'expires_at' => now()->subMinute(),
    ]);

    // Checked on read rather than swept by a job: a comp that outlives
    // its date because a scheduler was down is the failure this guards.
    expect(entitlements()->can($user, 'board.full'))->toBeFalse();
});

it('honours an override that has not expired yet', function () {
    $user = userOn(null);

    EntitlementOverride::create([
        'user_id' => $user->id,
        'feature' => 'board.full',
        'granted' => true,
        'reason' => 'Two week trial of the full board',
        'granted_by' => $user->id,
        'expires_at' => now()->addWeek(),
    ]);

    expect(entitlements()->can($user, 'board.full'))->toBeTrue();
});

it('refuses an override with no reason', function () {
    $user = userOn(null);

    expect(rejectedByDatabase(fn () => EntitlementOverride::create([
        'user_id' => $user->id,
        'feature' => 'board.full',
        'granted' => true,
        'granted_by' => $user->id,
    ])))->toThrow(QueryException::class);
});

it('keeps one override per user and feature', function () {
    $user = userOn(null);

    $row = [
        'user_id' => $user->id,
        'feature' => 'board.full',
        'granted' => true,
        'reason' => 'Comped',
        'granted_by' => $user->id,
    ];

    EntitlementOverride::create($row);

    expect(rejectedByDatabase(fn () => EntitlementOverride::create($row)))
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| What a subscription actually entitles you to
|--------------------------------------------------------------------------
*/

it('keeps a cancelled subscriber on their tier until the period they paid for ends', function () {
    $user = userOn('pro', [
        'status' => 'cancelled',
        'cancelled_at' => now()->subDay(),
        'cancel_at_period_end' => true,
        'current_period_end' => now()->addWeek(),
    ]);

    expect(entitlements()->tier($user))->toBe('pro');

    $this->actingAs($user)->get(route('picks'))->assertOk();
});

it('drops a cancelled subscriber to free once that period is over', function () {
    $user = userOn('pro', [
        'status' => 'cancelled',
        'cancelled_at' => now()->subMonths(2),
        'current_period_end' => now()->subMonth(),
    ]);

    // Free, not nothing: Free is a real tier with real features, and
    // locking somebody out of those is a decision nobody made.
    expect(entitlements()->tier($user))->toBe('free')
        ->and(entitlements()->can($user, 'board.today'))->toBeTrue()
        ->and(entitlements()->can($user, 'board.full'))->toBeFalse();
});

it('drops a past due subscriber to free only after the paid period ends', function () {
    $inGrace = userOn('pro', ['status' => 'past_due', 'current_period_end' => now()->addDays(3)]);
    $lapsed = userOn('pro', ['status' => 'past_due', 'current_period_end' => now()->subDays(3)]);

    expect(entitlements()->tier($inGrace))->toBe('pro')
        ->and(entitlements()->tier($lapsed))->toBe('free');
});

it('describes where a customer stands in words they would recognise', function () {
    $user = userOn('pro', ['cancel_at_period_end' => true, 'current_period_end' => now()->addWeek()]);

    expect($user->subscription->describe())
        ->toContain('Vigil Pro')
        ->toContain($user->subscription->current_period_end->toFormattedDateString());
});

it('resolves the brand placeholder in a tier name rather than printing it', function () {
    $user = userOn('daily');

    expect($user->subscription->describe())->not->toContain(':brand');
});

/*
|--------------------------------------------------------------------------
| Limits
|--------------------------------------------------------------------------
*/

it('tells unlimited and none apart', function () {
    $free = userOn(null);
    $daily = userOn('daily');

    // Null is unlimited and zero is none. A caller that confuses them
    // either locks everybody out or lets everybody through.
    expect(entitlements()->limit($free, 'parlays_per_day'))->toBe(3)
        ->and(entitlements()->limit($daily, 'parlays_per_day'))->toBeNull()
        ->and(entitlements()->limit($free, 'no_such_limit'))->toBeNull();
});

it('counts a cap as reached at the cap, not past it', function () {
    $free = userOn(null);

    expect(entitlements()->withinLimit($free, 'parlays_per_day', 2))->toBeTrue()
        ->and(entitlements()->withinLimit($free, 'parlays_per_day', 3))->toBeFalse()
        ->and(entitlements()->withinLimit(userOn('daily'), 'parlays_per_day', 9_000))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| One answer, asked two ways
|--------------------------------------------------------------------------
*/

it('answers the same through the gate as through the service', function () {
    $user = userOn(null);

    // Two ways to ask the question is how a Pro feature ends up hidden
    // in the UI and served by the route behind it, so the gate is
    // defined from the service rather than beside it.
    foreach (entitlements()->allFeatures() as $feature) {
        expect(Gate::forUser($user)->allows($feature))
            ->toBe(entitlements()->can($user, $feature));
    }
});

it('knows every feature it grants, and the cheapest tier that grants it', function () {
    foreach (entitlements()->allFeatures() as $feature) {
        expect(entitlements()->tierRequiredFor($feature))->not->toBeNull();
    }

    expect(entitlements()->tierRequiredFor('board.today'))->toBe('free')
        ->and(entitlements()->tierRequiredFor('alerts'))->toBe('daily')
        ->and(entitlements()->tierRequiredFor('board.full'))->toBe('pro')
        ->and(entitlements()->tierRequiredFor('time-travel'))->toBeNull();
});

it('gates every route against a feature the plans actually grant', function () {
    // A typo in a middleware argument gates a page against a feature
    // nobody can ever have, which looks exactly like a paywall working.
    foreach (gatedRoutes() as [$name, $feature]) {
        expect(entitlements()->allFeatures())->toContain($feature);
    }
});

/*
|--------------------------------------------------------------------------
| What the customer sees
|--------------------------------------------------------------------------
*/

it('shows a locked nav item pointing at the upgrade page rather than hiding it', function () {
    $this->actingAs(userOn(null))->get(route('today'))
        ->assertOk()
        // Shown, not hidden: a customer who cannot see the feature at
        // all cannot decide whether to pay for it.
        ->assertSee('AI Picks')
        ->assertSee(route('upgrade', ['feature' => 'board.full']), escape: false)
        ->assertSee('not on your plan');
});

it('does not lock the nav for a subscriber who has the feature', function () {
    $this->actingAs(userOn('pro'))->get(route('today'))
        ->assertOk()
        ->assertDontSee('not on your plan')
        ->assertSee(route('picks'), escape: false);
});

it('names the feature, the tier and the price on the upgrade page', function () {
    $this->actingAs(userOn(null))
        ->get(route('upgrade', ['feature' => 'board.full']))
        ->assertOk()
        ->assertSee('full value board')
        ->assertSee('Vigil Pro')
        ->assertSee('$59')
        // Nothing is on sale until step 6, and saying so beats a button
        // that takes a card for something the product cannot deliver.
        ->assertSee('not on sale yet');
});

it('still renders the upgrade page when it is reached with no feature named', function () {
    $this->actingAs(userOn(null))->get(route('upgrade'))->assertOk();
});

/*
|--------------------------------------------------------------------------
| The audit log
|--------------------------------------------------------------------------
*/

it('records who did an administrative write, to what, and why', function () {
    $actor = userOn('pro');
    $subject = userOn(null);

    $this->actingAs($actor);

    $override = EntitlementOverride::create([
        'user_id' => $subject->id,
        'feature' => 'board.full',
        'granted' => true,
        'reason' => 'Comped for a week after the outage',
        'granted_by' => $actor->id,
    ]);

    Audit::write('entitlement.granted', $override, null, $override->only(['feature', 'granted']), $override->reason);

    $event = AuditEvent::sole();

    expect($event->actor_id)->toBe($actor->id)
        ->and($event->action)->toBe('entitlement.granted')
        ->and($event->subject_type)->toBe('EntitlementOverride')
        ->and($event->subject_id)->toBe($override->id)
        ->and($event->after['feature'])->toBe('board.full')
        ->and($event->reason)->toContain('outage');
});

it('will not let an audit entry be rewritten or deleted', function () {
    $event = Audit::write('tier.changed', null, ['tier' => 'free'], ['tier' => 'pro'], 'Paid by bank transfer');

    expect(fn () => $event->update(['reason' => 'something else']))
        ->toThrow(RuntimeException::class, 'append-only');

    expect(fn () => $event->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});

/*
|--------------------------------------------------------------------------
| The dashboard, which is one page serving three tiers
|--------------------------------------------------------------------------
|
| The gated routes above are the easy half. The hard half is a page every
| tier can open, where the enforcement has to happen inside the page.
|
*/

function provenPick(int $id, array $overrides = []): void
{
    $row = boardRow($id, array_merge([
        'recommendation' => 'bet',
        'market_verdict' => 'proven',
        'model_prob' => 0.61,
        'edge_at_price' => 0.047,
    ], $overrides));

    mirrorEvent((int) $row['event_id'], $overrides['event'] ?? []);

    app(Mirror::class)->write(VlBoardEntry::class, $row, [
        'edge_lab_id' => (int) $row['id'],
        'edge_lab_event_id' => (int) $row['event_id'],
        'sport' => $row['sport'],
        'side' => $row['side'],
        'line' => $row['line'],
        'market' => $row['market'],
        'selection' => $row['selection'],
        'model_prob' => $row['model_prob'],
        'edge_at_price' => $row['edge_at_price'],
        'best_price_american' => $row['best_price_american'],
        'best_book_title' => $row['best_book_title'],
        'market_verdict' => $row['market_verdict'],
        'recommendation' => $row['recommendation'],
        'reason' => $row['reason'],
    ]);
}

it('shows a free user the top few picks and says how many it held back', function () {
    foreach (range(1, 7) as $id) {
        provenPick($id, ['edge_at_price' => 0.05 - $id / 1000]);
    }

    $this->actingAs(userOn(null))->get(route('today'))
        ->assertOk()
        // Three shown, four withheld, and the page says so. Telling a
        // customer there are no picks when there are seven would be a
        // lie told by omission.
        ->assertSee('4 more picks from proven markets today')
        ->assertSee(route('upgrade', ['feature' => 'card.daily']), escape: false);
});

it('shows a subscriber every pick with no held-back line', function () {
    foreach (range(1, 7) as $id) {
        provenPick($id);
    }

    $this->actingAs(userOn('daily'))->get(route('today'))
        ->assertOk()
        ->assertDontSee('more picks from proven markets today');
});

it('keeps the edge figure off the page for anybody below pro', function () {
    provenPick(1, ['edge_at_price' => 0.124]);

    foreach ([null, 'daily'] as $tier) {
        $this->actingAs(userOn($tier))->get(route('today'))
            ->assertOk()
            ->assertSee('61%')          // the pick is there
            ->assertDontSee('+12.4%');  // the edge is not
    }

    $this->actingAs(userOn('pro'))->get(route('today'))
        ->assertOk()
        ->assertSee('+12.4%');
});

it('does not tell a free user why the engine declined a game', function () {
    SyncRun::create([
        'feed' => 'board', 'status' => 'success', 'cursor_after' => 1,
        'started_at' => now()->subMinute(), 'finished_at' => now()->subMinute(),
    ]);

    provenPick(1, ['recommendation' => 'pass', 'market_verdict' => 'unproven']);

    // Why a game was passed on is the daily card's promise. Free is told
    // there is nothing to sell today, which is true, and nothing more.
    $this->actingAs(userOn(null))->get(route('today'))
        ->assertOk()
        ->assertSee('No proven picks today')
        ->assertDontSee('Model unproven here.');

    $this->actingAs(userOn('daily'))->get(route('today'))
        ->assertOk()
        ->assertSee('Model unproven here.');
});
