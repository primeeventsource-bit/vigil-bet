<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\BillingEvent;
use App\Models\EntitlementOverride;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\Admin\FeatureFlags;
use App\Services\Entitlements\Entitlements;
use Illuminate\Support\Facades\Route;

function admin(): User
{
    return User::forceCreate([
        'name' => 'Ops',
        'email' => 'ops'.fake()->unique()->randomNumber(6).'@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
}

/** Every GET page under /admin, read from the router. */
function adminPages(): array
{
    $pages = [];

    foreach (Route::getRoutes() as $route) {
        if (in_array('GET', $route->methods(), true)
            && str_starts_with((string) $route->getName(), 'admin.')
            && ! str_contains((string) $route->uri(), '{')) {
            $pages[] = $route->getName();
        }
    }

    return $pages;
}

/*
|--------------------------------------------------------------------------
| Who may see it
|--------------------------------------------------------------------------
*/

it('gates more than one admin page, so the checks below mean something', function () {
    expect(adminPages())->not->toBeEmpty();
});

it('answers 404 to a signed-in customer, not 403', function () {
    $customer = betUser('pro');

    foreach (adminPages() as $page) {
        // A 403 confirms the page exists. Somebody probing for /admin
        // should get the same answer as somebody asking for /nonsense.
        $this->actingAs($customer)->get(route($page))->assertNotFound();
    }
});

it('sends a signed-out visitor to the login first', function () {
    foreach (adminPages() as $page) {
        // A stranger should not be able to tell a non-admin account from
        // a non-existent page either.
        $this->get(route($page))->assertRedirect(route('login'));
    }
});

it('lets an admin into every page', function () {
    $ops = admin();

    foreach (adminPages() as $page) {
        $this->actingAs($ops)->get(route($page))->assertOk();
    }
});

it('refuses every admin write to a customer', function () {
    $customer = betUser('pro');
    $target = betUser(null);

    $this->actingAs($customer)->post(route('admin.flag'), [
        'key' => 'picks.publish', 'enabled' => 0, 'reason' => 'trying it on',
    ])->assertNotFound();

    $this->actingAs($customer)->post(route('admin.tier', $target), [
        'tier' => 'pro', 'reason' => 'trying it on',
    ])->assertNotFound();

    $this->actingAs($customer)->post(route('admin.override', $target), [
        'feature' => 'board.full', 'granted' => 1, 'reason' => 'trying it on',
    ])->assertNotFound();

    expect(AuditEvent::count())->toBe(0)
        ->and($target->refresh()->subscription)->toBeNull();
});

it('does not show a customer the admin link', function () {
    $this->actingAs(betUser('pro'))->get(route('today'))
        ->assertOk()
        ->assertDontSee(route('admin.overview'), escape: false);

    $this->actingAs(admin())->get(route('today'))
        ->assertOk()
        ->assertSee(route('admin.overview'), escape: false);
});

/*
|--------------------------------------------------------------------------
| §11: "Every admin write calls writeAudit()."
|--------------------------------------------------------------------------
*/

it('records every admin write with who did it and why', function () {
    $ops = admin();
    $customer = betUser(null);

    $this->actingAs($ops)->post(route('admin.override', $customer), [
        'feature' => 'board.full',
        'granted' => 1,
        'reason' => 'Comped while a billing dispute is sorted out',
    ])->assertSessionHasNoErrors();

    $audit = AuditEvent::sole();

    expect($audit->action)->toBe('entitlement.granted')
        ->and($audit->actor_id)->toBe($ops->id)
        ->and($audit->subject_id)->toBe($customer->id)
        ->and($audit->reason)->toContain('billing dispute')
        // What actually changed, beside what somebody meant. The two
        // sometimes differ.
        ->and($audit->after['granted'])->toBeTrue();
});

it('refuses an admin write with a reason too short to mean anything', function (string $reason) {
    $ops = admin();
    $customer = betUser(null);

    $this->actingAs($ops)->post(route('admin.override', $customer), [
        'feature' => 'board.full', 'granted' => 1, 'reason' => $reason,
    ])->assertSessionHasErrors('reason');

    // An entitlement granted with no note is indistinguishable from a
    // bug a month later, and a blank reason makes the audit log a list
    // of timestamps.
    expect(EntitlementOverride::count())->toBe(0)
        ->and(AuditEvent::count())->toBe(0);
})->with(['', '.', 'ok', 'fine']);

it('grants a feature that beats the plan, and records it', function () {
    $ops = admin();
    $customer = betUser(null);

    expect(app(Entitlements::class)->can($customer, 'board.full'))->toBeFalse();

    $this->actingAs($ops)->post(route('admin.override', $customer), [
        'feature' => 'board.full', 'granted' => 1, 'reason' => 'Comped for the outage on Tuesday',
    ]);

    expect(app(Entitlements::class)->can($customer->refresh(), 'board.full'))->toBeTrue();

    // And the customer can actually open the page.
    $this->actingAs($customer)->get(route('picks'))->assertOk();
});

it('withdraws a feature the plan grants, and records it', function () {
    $ops = admin();
    $customer = betUser('pro');

    $this->actingAs($ops)->post(route('admin.override', $customer), [
        'feature' => 'board.full', 'granted' => 0, 'reason' => 'Abuse of the line shopping page',
    ]);

    expect(app(Entitlements::class)->can($customer->refresh(), 'board.full'))->toBeFalse()
        ->and(AuditEvent::sole()->action)->toBe('entitlement.withdrawn');
});

it('removes an override and hands the decision back to the plan', function () {
    $ops = admin();
    $customer = betUser('pro');

    $this->actingAs($ops)->post(route('admin.override', $customer), [
        'feature' => 'board.full', 'granted' => 0, 'reason' => 'Temporary while we investigate',
    ]);

    $this->actingAs($ops)->delete(route('admin.override.remove', $customer), [
        'feature' => 'board.full', 'reason' => 'Investigation closed, nothing wrong',
    ])->assertSessionHasNoErrors();

    expect(EntitlementOverride::count())->toBe(0)
        ->and(app(Entitlements::class)->can($customer->refresh(), 'board.full'))->toBeTrue()
        ->and(AuditEvent::where('action', 'entitlement.override_removed')->exists())->toBeTrue();
});

it('records a hand-set tier as a billing event rather than editing the subscription', function () {
    $ops = admin();
    $customer = betUser(null);

    $this->actingAs($ops)->post(route('admin.tier', $customer), [
        'tier' => 'pro', 'reason' => 'Paid by bank transfer, invoice 1042',
    ])->assertSessionHasNoErrors();

    // The history stays complete and the projection is still derived
    // from the log. A tier set by an admin and one set by a payment
    // should be the same kind of fact.
    $event = BillingEvent::sole();

    expect($event->type)->toBe(BillingEvent::PLAN_CHANGED)
        ->and($event->tier)->toBe('pro')
        ->and($event->actor_id)->toBe($ops->id)
        ->and($event->reason)->toContain('1042');

    expect($customer->refresh()->subscription->tier)->toBe('pro')
        ->and(AuditEvent::where('action', 'tier.changed')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Feature flags
|--------------------------------------------------------------------------
*/

it('reads a flag that nobody has touched at its documented default', function () {
    $flags = app(FeatureFlags::class);

    // A flag read before it was ever written should get its default
    // rather than a null.
    expect($flags->enabled('picks.publish'))->toBeTrue()
        ->and($flags->enabled('billing.checkout'))->toBeFalse();
});

it('treats an unknown flag as off', function () {
    // A typo must not switch something on, and a flag that does not
    // exist is not a feature that is enabled.
    expect(app(FeatureFlags::class)->enabled('nonsense.key'))->toBeFalse();
});

it('throws a flag, records who did it, and takes effect at once', function () {
    $ops = admin();

    $this->actingAs($ops)->post(route('admin.flag'), [
        'key' => 'picks.publish',
        'enabled' => 0,
        'reason' => 'Edge Lab verdicts look wrong, stopping the record',
    ])->assertSessionHasNoErrors();

    // Cache cleared on write, so a deliberate change is immediate.
    expect(app(FeatureFlags::class)->enabled('picks.publish'))->toBeFalse()
        ->and(FeatureFlag::sole()->updated_by)->toBe($ops->id);

    $audit = AuditEvent::where('action', 'flag.disabled')->sole();

    expect($audit->before['enabled'])->toBeTrue()
        ->and($audit->after['enabled'])->toBeFalse()
        ->and($audit->reason)->toContain('verdicts look wrong');
});

it('refuses a flag nobody declared', function () {
    $this->actingAs(admin())->post(route('admin.flag'), [
        'key' => 'made.up', 'enabled' => 1, 'reason' => 'seeing what happens',
    ])->assertSessionHasErrors('key');

    expect(FeatureFlag::count())->toBe(0);
});

it('lists every declared flag, not only the ones somebody touched', function () {
    $this->actingAs(admin())->get(route('admin.overview'))
        ->assertOk()
        ->assertSee('picks.publish')
        ->assertSee('billing.checkout')
        // The sentence saying what it does, because a flag nobody can
        // explain is a flag nobody dares turn off.
        ->assertSee('kill switch for the record');
});

/*
|--------------------------------------------------------------------------
| What admin deliberately cannot do
|--------------------------------------------------------------------------
*/

it('has no route that edits a bet, a pick, a billing event or an audit entry', function () {
    $writes = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.'))
        ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
        ->map(fn ($route) => $route->getName())
        ->values();

    /*
     * An admin area that can change anything is an admin area that
     * eventually does. These five are the only writes, and the tables
     * behind everything else are append-only in the database - this
     * controller could not write them if it tried.
     */
    expect($writes->all())->toEqualCanonicalizing([
        'admin.override',
        'admin.override.remove',
        'admin.tier',
        'admin.refund',
        'admin.flag',
    ]);
});

it('cannot rewrite an audit entry even from admin', function () {
    $ops = admin();
    $customer = betUser(null);

    $this->actingAs($ops)->post(route('admin.override', $customer), [
        'feature' => 'board.full', 'granted' => 1, 'reason' => 'Comped for the outage',
    ]);

    expect(fn () => AuditEvent::sole()->update(['reason' => 'something else']))
        ->toThrow(RuntimeException::class, 'append-only');
});

it('refuses a refund against an event that charged nothing', function () {
    $event = BillingEvent::create([
        'driver' => 'none', 'type' => BillingEvent::CANCEL_SCHEDULED,
        'user_id' => betUser(null)->id, 'occurred_at' => now(), 'created_at' => now(),
    ]);

    $this->actingAs(admin())->post(route('admin.refund'), [
        'billing_event_id' => $event->id, 'reason' => 'Customer asked for their money back',
    ])->assertSessionHasErrors('refund');
});

it('refuses a refund when the payload carries no processor charge id', function () {
    $event = BillingEvent::create([
        'driver' => 'nmi', 'type' => BillingEvent::RENEWED,
        'user_id' => betUser(null)->id, 'amount_cents' => 5900,
        'payload' => ['event_type' => 'transaction.sale.success'],
        'occurred_at' => now(), 'created_at' => now(),
    ]);

    $this->actingAs(admin())->post(route('admin.refund'), [
        'billing_event_id' => $event->id, 'reason' => 'Customer asked for their money back',
    ])->assertSessionHasErrors('refund');

    // A refund sent against the wrong charge is worse than one that did
    // not happen.
    expect(BillingEvent::where('type', BillingEvent::REFUNDED)->count())->toBe(0);
});

it('refuses a refund larger than the original charge', function () {
    $event = BillingEvent::create([
        'driver' => 'nmi', 'type' => BillingEvent::RENEWED,
        'user_id' => betUser(null)->id, 'amount_cents' => 1900,
        'payload' => ['transaction_id' => 'txn_1'],
        'occurred_at' => now(), 'created_at' => now(),
    ]);

    $this->actingAs(admin())->post(route('admin.refund'), [
        'billing_event_id' => $event->id, 'amount' => 59.00,
        'reason' => 'Customer asked for their money back',
    ])->assertSessionHasErrors('refund');
});
