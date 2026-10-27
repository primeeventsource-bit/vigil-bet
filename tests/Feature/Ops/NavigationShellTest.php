<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| The application shell
|--------------------------------------------------------------------------
|
| The sidebar was `hidden lg:flex`, which meant that below 1024px the
| product had no navigation at all beyond the logo: the dashboard, the
| parlay builder, the bets and the settings were reachable only by typing
| the URL. Most people who bet on their phone could not get to any of it.
|
| So there is a drawer now, and these tests hold the two halves of it
| together - the button in the header and the panel it controls are in
| different components, and an `aria-controls` pointing at an id that has
| been renamed is a control that silently stops announcing what it does.
|
*/

function shellUser(?string $tier = null): User
{
    $user = User::forceCreate([
        'name' => 'Sam Vigil',
        'email' => 'sam'.fake()->unique()->randomNumber(6).'@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
    ]);

    if ($tier !== null) {
        Subscription::create([
            'user_id' => $user->id,
            'tier' => $tier,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
            'source' => 'test',
        ]);
    }

    return $user->refresh();
}

/* ----------------------------------------------------------------------
 | The drawer is wired to the thing it opens
 ---------------------------------------------------------------------- */

it('points the menu button at the panel that actually exists', function () {
    $page = $this->actingAs(shellUser())->get(route('today'))->assertOk()->getContent();

    // Both halves, and the same id in both. The button lives in the
    // topbar and the panel in the sidebar, so nothing but a test keeps
    // them agreeing.
    expect($page)->toContain('aria-controls="app-nav"')
        ->and($page)->toContain('id="app-nav"')
        ->and($page)->toContain('data-nav-toggle')
        ->and($page)->toContain('data-nav-panel');
});

it('ships the button hidden, for a visitor whose scripting never runs', function () {
    // Same bargain as the theme toggle: the script reveals it. A button
    // that does nothing is worse than no button, and the desktop
    // sidebar does not depend on any of this.
    $page = $this->actingAs(shellUser())->get(route('today'))->assertOk()->getContent();

    expect($page)->toMatch('/data-nav-toggle\s+hidden/');
});

it('says the drawer is shut before anything has opened it', function () {
    $page = $this->actingAs(shellUser())->get(route('today'))->assertOk()->getContent();

    expect($page)->toContain('aria-expanded="false"');
});

/* ----------------------------------------------------------------------
 | Grouping, which only an admin sees
 ---------------------------------------------------------------------- */

it('groups the engine and admin pages for whoever runs the product', function () {
    $admin = shellUser('pro');
    $admin->forceFill(['is_admin' => true])->save();

    $this->actingAs($admin)->get(route('today'))
        ->assertOk()
        ->assertSee('Engine')
        ->assertSee('Admin')
        // Still every page it had before the restructure.
        ->assertSee('Board')
        ->assertSee('Models')
        ->assertSee('Reports');
});

it('gives a customer no headings to read past', function () {
    // One group, so no label. A customer's nav is ten items and a
    // heading over a single list is furniture.
    $this->actingAs(shellUser('daily'))->get(route('today'))
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertDontSee('>Engine<', false);
});

/* ----------------------------------------------------------------------
 | The plan card names the plan rather than selling one
 ---------------------------------------------------------------------- */

it('names the tier the customer is actually on', function (string $tier, string $expected) {
    $this->actingAs(shellUser($tier))->get(route('today'))
        ->assertOk()
        ->assertSee('Your plan')
        ->assertSee($expected);
})->with([
    ['daily', 'Daily'],
    ['pro', 'Pro'],
]);

it('calls a signed-in user with no subscription what the config calls it', function () {
    $this->actingAs(shellUser())->get(route('today'))
        ->assertOk()
        ->assertSee('Free');
});

it('offers the prices to anybody not already on the top tier', function () {
    $this->actingAs(shellUser('free'))->get(route('today'))
        ->assertOk()
        ->assertSee('Compare plans');
});

it('stops selling to somebody who has already bought the most expensive thing', function () {
    // The reference layout draws a permanent upgrade card here. An
    // upgrade button shown to the top tier is either a dead end or a
    // lie about there being more to buy.
    $this->actingAs(shellUser('pro'))->get(route('today'))
        ->assertOk()
        ->assertDontSee('Compare plans');
});

it('promises no feature it cannot point at', function () {
    // The reference card advertised unlimited CLV model signals and
    // instant Telegram steam alerts. There is no Telegram integration.
    $page = $this->actingAs(shellUser('pro'))->get(route('today'))->assertOk()->getContent();

    expect($page)->not->toContain('Telegram');
});
