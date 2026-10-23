<?php

declare(strict_types=1);

use App\Models\BillingEvent;
use App\Models\PublishedPick;
use App\Models\Subscription;
use App\Models\SyncRun;
use App\Services\Ai\ReadValidator;
use App\Services\Bets\BetLedger;
use App\Services\Entitlements\Entitlements;
use App\Services\Payments\Nmi\NmiGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\Stripe\StripeGateway;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| §13 — the acceptance checks, in one place
|--------------------------------------------------------------------------
|
| Each of the spec's eight checks, asserted here as well as wherever the
| feature's own tests live. The duplication is the point: a check listed
| in the spec should be findable by reading the spec, not by knowing which
| of forty test files happens to cover it. If one of these fails, the
| product does not meet its acceptance criteria - whatever else passes.
|
*/

/* 1 ------------------------------------------------------------------- */

it('13.1: a free user cannot reach pro data through any route or json response', function () {
    $free = betUser(null);

    $gated = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'entitled:')) {
                $gated[$route->getName()] = explode(':', $middleware, 2)[1];
            }
        }
    }

    expect($gated)->not->toBeEmpty();

    $checked = 0;

    foreach ($gated as $name => $feature) {
        if (app(Entitlements::class)->can($free, $feature)) {
            // Gated against something Free does have - parlays, so the
            // daily cap has somewhere to live. Not a paywall.
            continue;
        }

        // HTML: redirected to the upgrade page.
        $this->actingAs($free)->get(route($name))
            ->assertRedirect(route('upgrade', ['feature' => $feature]));

        // JSON: 403 and nothing else. No partially rendered data.
        $json = $this->actingAs($free)->getJson(route($name))->assertForbidden();

        expect(array_keys($json->json()))
            ->toEqualCanonicalizing(['error', 'feature', 'required_tier']);

        $checked++;
    }

    expect($checked)->toBeGreaterThan(0);
});

/* 2 ------------------------------------------------------------------- */

it('13.2: with no proven market the daily card shows zero picks and says so', function () {
    $user = betUser('pro');

    SyncRun::create([
        'feed' => 'board', 'status' => 'success', 'cursor_after' => 1,
        'started_at' => now()->subMinute(), 'finished_at' => now()->subMinute(),
    ]);

    // A full board of candidates, none of them from a proven market.
    foreach (range(1, 5) as $i) {
        entryFor(['id' => $i, 'recommendation' => 'pass', 'market_verdict' => 'unproven']);
    }

    $this->actingAs($user)->get(route('today'))
        ->assertOk()
        ->assertSee('No proven picks today')
        // And no bet button anywhere on it.
        ->assertDontSee('Add to Bet Slip');
});

/* 3 ------------------------------------------------------------------- */

it('13.3: every number in a read exists in its fact sheet', function () {
    /*
     * Proved across a fixture set in ReadValidationTest. Asserted here
     * on the two cases that matter most: a figure from the sheet is
     * accepted, and a plausible invented one is not.
     */
    $sheet = ['model_probability' => 0.58, 'model_probability_written' => '58%'];
    $padding = ' '.str_repeat('The engine measured this against the closing line and recorded it. ', 8);

    $validator = app(ReadValidator::class);

    expect($validator->check('The model puts this at 58%.'.$padding, $sheet)['ok'])->toBeTrue()
        ->and($validator->check('The model puts this at 61%.'.$padding, $sheet)['ok'])->toBeFalse();
});

/* 4 ------------------------------------------------------------------- */

it('13.4: a published pick timestamped after kickoff is rejected by the database', function () {
    expect(fn () => PublishedPick::create([
        'edge_lab_event_id' => 1,
        'sport' => 'nfl', 'market' => 'h2h', 'side' => 'home', 'selection' => 'Chiefs',
        'price_american' => -110,
        'kickoff_at' => now()->subHour(),
        'published_at' => now(),
        'created_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(PublishedPick::count())->toBe(0);
});

/* 5 ------------------------------------------------------------------- */

it('13.5: no endpoint returns odds for more than one event, and no export includes odds', function () {
    /*
     * The licence boundary. It is about data leaving in bulk, not about
     * the word "odds" in a URL - a signed-in HTML page showing prices is
     * the permitted case. So the rule is enforced two ways.
     *
     * First: no machine-readable endpoint. The one export that exists is
     * the customer's own bets, which §8 asks for by name.
     */
    $dataShaped = '/\bapi\b|feed|\.csv|\.json|download|bulk/i';

    $offenders = collect(Route::getRoutes())
        ->reject(fn ($route) => str_starts_with($route->uri(), 'livewire/'))
        ->reject(fn ($route) => str_starts_with($route->uri(), 'storage/'))
        ->reject(fn ($route) => $route->uri() === 'bets/export')
        ->filter(fn ($route) => (bool) preg_match($dataShaped, $route->uri()))
        ->map(fn ($route) => $route->uri());

    expect($offenders->values()->all())->toBe([]);

    /*
     * Second: the one export there is carries only this customer's own
     * bets, even with a full board of prices in the database. A bet
     * export is exactly where an odds slate would be smuggled out.
     */
    $user = betUser('pro');
    fullBoard(games: 4);

    app(BetLedger::class)->manual($user, [
        'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Only mine',
        'price_american' => -110, 'stake_cents' => 1000,
    ]);

    $csv = $this->actingAs($user)->get(route('bets.export'))->assertOk()->getContent();

    // One header row and one bet, with four games and seventy-two
    // quotes sitting in the database beside it.
    expect(substr_count(trim($csv), "\n"))->toBe(1)
        ->and($csv)->toContain('Only mine')
        ->and($csv)->not->toContain('Home 1');
});

it('13.5: every page that shows prices is behind a login', function () {
    /*
     * Display inside a paid interface is what the licence permits.
     * Display to the open internet is not, so a page showing prices has
     * to be behind the login - which the sample board on the marketing
     * home page is deliberately not, because it shows no prices.
     */
    foreach (['live-odds', 'picks', 'props', 'tools', 'today'] as $page) {
        $this->get(route($page))->assertRedirect(route('login'));
    }
});

/* 6 ------------------------------------------------------------------- */

it('13.6: changing the brand name renames the product everywhere visible', function () {
    config()->set('brand.name', 'Testbrand');

    /*
     * The legal entity name is a separate setting with its own
     * environment variable, and deliberately so: a company does not
     * rename itself at Companies House because the product got a new
     * name. It is set here too so the assertion below can be absolute
     * about the old product name rather than carving out an exception
     * nobody reading it would understand.
     */
    config()->set('brand.company.legal_name', 'Testbrand Analytics');

    $user = betUser('pro');

    foreach ([route('home'), route('pricing'), route('record')] as $url) {
        $body = $this->get($url)->assertOk()->getContent();

        expect($body)->toContain('Testbrand')
            // The old name must not survive anywhere in the markup.
            ->and($body)->not->toContain('Vigil');
    }

    $body = $this->actingAs($user)->get(route('today'))->assertOk()->getContent();

    expect($body)->not->toContain('Vigil');
});

/* 7 ------------------------------------------------------------------- */

it('13.7: switching the payment driver takes no code changes', function (string $driver, string $class) {
    usingDriver($driver);

    expect(app(PaymentGateway::class))->toBeInstanceOf($class);

    Http::fake([
        'nmi.test/api/v5/customers' => Http::response(['id' => 'vault_1']),
        'nmi.test/api/v5/subscriptions' => Http::response(['id' => 'nmi_sub_1']),
        'stripe.test/api/v1/customers' => Http::response(['id' => 'cus_1']),
        'stripe.test/api/v1/subscriptions' => Http::response([
            'id' => 'sub_1', 'current_period_end' => now()->addMonth()->timestamp,
        ]),
    ]);

    $user = betUser(null);

    $this->actingAs($user)->post(route('billing.subscribe'), [
        'tier' => 'pro', 'interval' => 'monthly', 'payment_token' => 'tok_browser',
    ])->assertRedirect(route('account'));

    // Same request, same outcome, nothing changed but configuration.
    $event = BillingEvent::sole();

    expect($event->driver)->toBe($driver)
        ->and($event->type)->toBe(BillingEvent::SUBSCRIBED)
        ->and($event->tier)->toBe('pro')
        ->and($user->refresh()->subscription->effectiveTier())->toBe('pro');
})->with([
    ['nmi', NmiGateway::class],
    ['stripe', StripeGateway::class],
]);

/* 8 ------------------------------------------------------------------- */

it('13.8: cancelling takes one click and keeps access until period end', function () {
    usingDriver('nmi');
    Http::fake(['nmi.test/*' => Http::response([])]);

    $user = betUser('pro');

    Subscription::where('user_id', $user->id)->update([
        'gateway' => 'nmi',
        'gateway_customer_id' => 'cust_1',
        'gateway_subscription_id' => 'sub_1',
        'current_period_end' => now()->addDays(20),
    ]);

    // One click: the page offers exactly one route to cancel and it is
    // a direct POST, so there is no interstitial for a retention offer
    // to live on.
    $page = $this->actingAs($user)->get(route('account'))->assertOk();

    expect(substr_count($page->getContent(), route('billing.cancel')))->toBe(1);

    $this->actingAs($user)->post(route('billing.cancel'))->assertRedirect(route('account'));

    // Access kept.
    expect($user->refresh()->subscription->effectiveTier())->toBe('pro');
    $this->actingAs($user)->get(route('picks'))->assertOk();

    // And it stops on the date, not on a webhook that may never arrive.
    $this->travel(21)->days();

    expect($user->refresh()->subscription->effectiveTier())->toBe('free');
    $this->actingAs($user)->get(route('picks'))
        ->assertRedirect(route('upgrade', ['feature' => 'board.full']));
});
