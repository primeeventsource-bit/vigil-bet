<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;

it('keeps app pages behind a login', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['today', 'account', 'two-factor.show']);

it('keeps app pages behind a confirmed email', function (string $route) {
    $user = User::forceCreate([
        'name' => 'Unverified',
        'email' => 'unverified@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => null,
    ]);

    $this->actingAs($user)->get(route($route))->assertRedirect(route('verification.notice'));
})->with(['today', 'account', 'two-factor.show']);

it('lets a verified user in', function () {
    $user = User::forceCreate([
        'name' => 'Sam',
        'email' => 'sam@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)->get(route('today'))->assertOk();
});

/*
 * App pages are noindex and marketing pages are not. The head partial
 * defaults to noindex so that a page which forgets the flag fails safe,
 * and these two tests are what keep that default honest.
 */
it('keeps every app page out of search results', function (string $route) {
    $user = User::forceCreate([
        'name' => 'Sam',
        'email' => 'sam@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)->get(route($route))
        ->assertOk()
        ->assertSee('name="robots" content="noindex, nofollow"', escape: false);
})->with(['today', 'account', 'two-factor.show']);

it('has no route of ours that writes anything without a session', function () {
    /*
     * The ways in, which cannot require a session by definition. Each one
     * is throttled instead, which the test below proves. Listed by URI
     * rather than by name because the POST half of a sign-in pair shares
     * its name with nothing - the name is on the GET that renders the form.
     */
    $waysIn = ['register', 'login', 'two-factor', 'forgot-password', 'reset-password'];

    /*
     * Server to server, and so cannot carry a session or a CSRF token.
     * What stands in for both is a signature the driver verifies over
     * the raw body before anything is parsed, plus an event id that can
     * only be written once. Listed here so that adding another such
     * endpoint is a deliberate edit to this test rather than a silent
     * pass.
     */
    $signedByAProcessor = ['webhooks/payments/{driver}'];

    $unprotected = collect(Route::getRoutes())
        ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
        // Framework and package routes. Livewire and the storage uploader
        // bring their own, and they are not ours to gate.
        ->reject(fn ($route) => str_starts_with($route->uri(), 'livewire/'))
        ->reject(fn ($route) => str_starts_with($route->uri(), 'storage/'))
        ->reject(fn ($route) => in_array($route->uri(), $waysIn, true))
        ->reject(fn ($route) => in_array($route->uri(), $signedByAProcessor, true))
        ->reject(fn ($route) => in_array('auth', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->uri().' ['.$route->getName().']')
        ->values();

    expect($unprotected->all())->toBe([]);
});

it('throttles every unauthenticated write', function (string $uri) {
    $route = collect(Route::getRoutes())->first(
        fn ($r) => $r->uri() === $uri && in_array('POST', $r->methods(), true)
    );

    expect(collect($route->gatherMiddleware())->contains(fn ($m) => str_starts_with((string) $m, 'throttle:')))
        ->toBeTrue();
})->with(['register', 'login', 'two-factor', 'forgot-password', 'reset-password']);
