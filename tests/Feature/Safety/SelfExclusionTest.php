<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\SelfExclusion as SelfExclusionModel;
use App\Models\UserBet;
use App\Services\Bets\BetLedger;
use App\Services\Safety\SelfExclusion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| §9: "Self-exclusion: user can lock themselves out for 7, 30 or 90 days;
| cannot be undone early."
|--------------------------------------------------------------------------
|
| The second clause is the whole feature. A self-exclusion a customer can
| lift is not a self-exclusion - it is a cooling-off button, and the
| person it exists to protect is precisely the person who will come
| looking for the way out at two in the morning.
|
| So most of what is below asserts the absence of something.
|
*/

it('locks a customer out for a duration they chose', function (int $days) {
    $user = betUser('pro');

    $this->actingAs($user)->post(route('safety.exclude'), [
        'days' => $days, 'acknowledged' => '1',
    ]);

    $exclusion = SelfExclusionModel::sole();

    expect($exclusion->days)->toBe($days)
        ->and($exclusion->isActive())->toBeTrue()
        ->and($exclusion->ends_at->isAfter(now()->addDays($days - 1)))->toBeTrue();
})->with(SelfExclusionModel::DURATIONS);

it('offers only the three durations the spec names', function () {
    expect(SelfExclusionModel::DURATIONS)->toBe([7, 30, 90]);

    $user = betUser('pro');

    /*
     * A field that accepts any value accepts 1, and a one-day exclusion
     * is a button that lets somebody feel they have done something.
     *
     * Four values rather than a dozen: the endpoint is throttled to five
     * a minute, and a test that tripped its own rate limit would be
     * asserting the throttle rather than the validation.
     */
    foreach ([1, 14, 365, 0] as $days) {
        $this->actingAs($user)->post(route('safety.exclude'), [
            'days' => $days, 'acknowledged' => '1',
        ])->assertSessionHasErrors('days');
    }

    expect(SelfExclusionModel::count())->toBe(0);
});

it('will not start one without the acknowledgement ticked', function () {
    $user = betUser('pro');

    $this->actingAs($user)->post(route('safety.exclude'), ['days' => 30])
        ->assertSessionHasErrors('acknowledged');

    expect(SelfExclusionModel::count())->toBe(0);
});

it('stores the exact sentence the customer agreed to', function () {
    $user = betUser('pro');

    $this->actingAs($user)->post(route('safety.exclude'), [
        'days' => 30, 'acknowledged' => '1',
    ]);

    // This is the one decision in the product somebody may later say
    // they did not understand, and the answer should not depend on what
    // the page happens to say today.
    expect(SelfExclusionModel::sole()->acknowledged)
        ->toContain('30 days')
        ->toContain('cannot be shortened or cancelled');
});

/* ----------------------------------------------------------------------
 | It cannot be undone
 ---------------------------------------------------------------------- */

it('has no route anywhere that ends a self-exclusion', function () {
    $routes = collect(Route::getRoutes())
        ->map(fn ($route) => $route->uri().' '.implode(',', $route->methods()))
        ->filter(fn (string $signature) => (bool) preg_match(
            '/exclu/i',
            $signature,
        ))
        ->values();

    /*
     * Three routes touch self-exclusion: the page, the POST that starts
     * one, and the public page a locked-out customer lands on. Nothing
     * that cancels, shortens, or lifts - not for the customer and not
     * for an admin, because an operator who can be talked into lifting
     * it is the same hole with an extra step.
     */
    expect($routes->all())->toEqualCanonicalizing([
        'account/self-exclude POST',
        'excluded GET,HEAD',
    ]);
});

it('refuses to rewrite or delete an exclusion', function () {
    $exclusion = app(SelfExclusion::class)->start(betUser('pro'), 90);

    expect(fn () => $exclusion->update(['ends_at' => now()->addMinute()]))
        ->toThrow(RuntimeException::class, 'append-only');

    expect(fn () => $exclusion->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});

it('refuses an exclusion that ends before it starts, at the database', function () {
    // Belt and braces. The service computes ends_at and cannot get this
    // wrong, so the constraint is there for everything that is not the
    // service: a seeder, a raw query, an import.
    expect(fn () => SelfExclusionModel::create([
        'user_id' => betUser('pro')->id,
        'days' => 30,
        'starts_at' => now(),
        'ends_at' => now()->subDay(),
        'acknowledged' => 'whatever',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('does not let a second, shorter exclusion replace a longer one', function () {
    $user = betUser('pro');
    $service = app(SelfExclusion::class);

    $long = $service->start($user, 90);
    $short = $service->start($user, 7);

    // Starting a new one while excluded could only ever shorten it,
    // which is the thing that must not be possible.
    expect($short->id)->toBe($long->id)
        ->and(SelfExclusionModel::count())->toBe(1)
        ->and($service->active($user)->days)->toBe(90);
});

/* ----------------------------------------------------------------------
 | It actually locks them out
 ---------------------------------------------------------------------- */

it('signs an excluded customer out of every page, not just the odds', function (string $page) {
    $user = betUser('pro');

    app(SelfExclusion::class)->start($user, 30);

    $this->actingAs($user)->get(route($page))->assertRedirect(route('excluded'));

    /*
     * A customer who has locked themselves out should not be able to
     * read their own ROI either. The point is distance from the
     * product, not distance from one page of it.
     */
    expect(auth()->check())->toBeFalse();
})->with(['today', 'bets', 'account', 'picks', 'parlays', 'bankroll']);

it('invalidates the session so an open tab is not a way back in', function () {
    $user = betUser('pro');

    $this->actingAs($user)->get(route('today'))->assertOk();

    app(SelfExclusion::class)->start($user, 7);

    $this->get(route('today'))->assertRedirect(route('excluded'));

    // The session is gone, not merely unauthenticated for one request.
    $this->get(route('today'))->assertRedirect(route('login'));
});

it('will not let an excluded customer sign in again', function () {
    $user = betUser('pro');
    app(SelfExclusion::class)->start($user, 90);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'correct-horse-battery-staple',
    ]);

    // Whether the password is right is beside the point. The next
    // authenticated request is bounced by the middleware and the
    // session is thrown away, so there is no way in.
    $this->get(route('today'))->assertRedirect(route('excluded'));
});

it('lets them back in once the time has run out', function () {
    $user = betUser('pro');

    app(SelfExclusion::class)->start($user, 7);

    $this->actingAs($user)->get(route('today'))->assertRedirect(route('excluded'));

    $this->travel(8)->days();

    // The only thing that ends it.
    expect(app(SelfExclusion::class)->isExcluded($user->refresh()))->toBeFalse();

    $this->actingAs($user)->get(route('today'))->assertOk();
});

it('keeps their account, bets and record untouched', function () {
    $user = betUser('pro');

    app(BetLedger::class)->manual($user, [
        'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Bills',
        'price_american' => -110, 'stake_cents' => 2500,
    ]);

    app(SelfExclusion::class)->start($user, 30);

    // Nothing is deleted. A customer who comes back should find their
    // record where they left it.
    expect($user->exists)->toBeTrue()
        ->and(UserBet::where('user_id', $user->id)->count())->toBe(1);
});

it('tells a locked-out customer when it lifts, and offers no way round it', function () {
    $user = betUser('pro');
    app(SelfExclusion::class)->start($user, 30);

    $page = $this->actingAs($user)->get(route('today'))
        ->assertRedirect(route('excluded'));

    $body = $this->followRedirects($page)->assertOk()->getContent();

    expect($body)->toContain('locked out')
        ->toContain('cannot be shortened or cancelled')
        // The helpline, not a support link that cannot help.
        ->toContain(config('vigil.responsible_gambling_line'));
});

it('records that the customer asked for it', function () {
    $user = betUser('pro');

    app(SelfExclusion::class)->start($user, 90);

    $audit = AuditEvent::where('action', 'self_exclusion.started')->sole();

    // Audited not because an operator did it - the customer did - but
    // because support will be asked about it, and the answer has to
    // come from a row rather than from memory.
    expect($audit->subject_id)->toBe($user->id)
        ->and($audit->after['days'])->toBe(90)
        ->and($audit->reason)->toContain('customer');
});

it('does not gate self-exclusion behind a plan', function () {
    // §9 is a compliance requirement, not a feature of a tier.
    $this->actingAs(betUser(null))->get(route('safety'))->assertOk();
});
