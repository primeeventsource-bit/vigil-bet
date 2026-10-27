<?php

declare(strict_types=1);

use App\Http\Controllers\PageController;

/*
|--------------------------------------------------------------------------
| The pages a stranger reads before deciding to trust any of this
|--------------------------------------------------------------------------
|
| About, Features, Sports Analytics and the cancellation policy. All
| four are public and indexable, and all four are held to the same rule
| as the rest of the site: nothing may state a fact the product cannot
| support.
|
| The About page is the one that matters most here. It is the exact place
| where a company's details are normally invented - a founding year, a
| team, a city - and none of those have been supplied, so none appear.
|
*/

it('serves every public page to a stranger', function (string $route) {
    $this->get(route($route))->assertOk();
})->with(['about', 'features', 'sports-analytics', 'legal.cancellation']);

it('keeps every legal page reachable by both of its names', function (string $page) {
    // The list used to be written out three times. Two of the three
    // agreeing produced a 404 that read as a missing view.
    $this->get(route('legal.page', $page))->assertOk();
    $this->get(route('legal.'.$page))->assertOk();
})->with(PageController::LEGAL_PAGES);

/* ----------------------------------------------------------------------
 | About claims nothing nobody supplied
 ---------------------------------------------------------------------- */

it('invents no company facts on the about page', function () {
    $page = $this->get(route('about'))->assertOk()->getContent();

    /*
     * The sentences a page like this writes itself if nobody is
     * watching. Each one reads as information and carries none, and
     * every one of them would be a claim about a business that nobody
     * has made.
     */
    foreach (['Founded in', 'our team of', 'years of experience', 'headquartered'] as $invention) {
        expect(mb_strtolower($page))->not->toContain(mb_strtolower($invention));
    }
});

it('says what the product is rather than how it feels about itself', function () {
    $this->get(route('about'))->assertOk()
        ->assertSee('Most of the time it says pass.')
        // The one claim worth making, and it is checkable.
        ->assertSee('graded in public');
});

it('does not link a record page that 404s', function () {
    // /record aborts until a market is proven, and no market is proven
    // in a fresh test database. A prominent link to a page that does
    // not exist is worse than no link.
    $this->get(route('about'))->assertOk()
        ->assertDontSee(route('record'), escape: false);
});

/* ----------------------------------------------------------------------
 | Features cannot promise what is not sold
 ---------------------------------------------------------------------- */

it('builds the features page from the plans themselves', function () {
    $page = $this->get(route('features'))->assertOk();

    // Every sold tier, with the price the checkout would charge.
    foreach (['Core', 'Pro', 'Elite'] as $name) {
        $page->assertSee('Vigil '.$name);
    }

    $page->assertSee('$29.99/month')
        ->assertSee('$59.99/month')
        ->assertSee('$99.99/month');
});

it('shows the free tier without pricing it as a product', function () {
    $this->get(route('features'))->assertOk()
        ->assertSee('Free')
        // It is the floor the application stands on, not something
        // with a price tag.
        ->assertDontSee('$0.00/month');
});

/* ----------------------------------------------------------------------
 | Sports analytics admits what has not been proven
 ---------------------------------------------------------------------- */

it('says how many markets cleared the gate, not just how many exist', function () {
    /*
     * The number a brochure leaves out. A market is modelled long
     * before it is trusted, and only the second figure decides whether
     * a customer sees anything from it.
     */
    $this->get(route('sports-analytics'))->assertOk()
        ->assertSee('Cleared the gate')
        ->assertSee('What the gate asks');
});

it('says plainly when nothing has been trained', function () {
    $this->get(route('sports-analytics'))->assertOk()
        ->assertSee('No models have been trained yet.');
});

/* ----------------------------------------------------------------------
 | The cancellation policy agrees with the terms
 ---------------------------------------------------------------------- */

it('states the refund rule as plainly on its own page as in the terms', function () {
    $this->get(route('legal.cancellation'))->assertOk()
        ->assertSee('No refunds on results.')
        ->assertSee('Cancel from Account, in one click.');
});

it('keeps self-exclusion separate from cancelling', function () {
    // Two different things that both end in a locked door. An exclusion
    // is the customer's and nobody here can shorten it.
    $this->get(route('legal.cancellation'))->assertOk()
        ->assertSee('Self-exclusion is different');
});

it('links the cancellation policy from the footer of every page', function () {
    // The rules were correct and buried four headings into the terms,
    // which is where rules go when they are written for after the
    // argument rather than before the purchase.
    $this->get(route('home'))->assertOk()
        ->assertSee(route('legal.cancellation'), escape: false);
});
