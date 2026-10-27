<?php

declare(strict_types=1);

use App\Models\PublishedPick;

/*
|--------------------------------------------------------------------------
| What the site shows when nothing has passed the gate
|--------------------------------------------------------------------------
|
| For months the answer was: everything. No market had ever been proven -
| the live verdicts were calibrated, unproven, negative and
| insufficient_data, and not one proven among them - and the site still
| carried a public track record with a win rate and units on it.
|
| `Calibrated` was reaching the pick publisher because allowsRecommendation
| accepted it, while claimsEdge in the same enum said in as many words
| that nothing reading as "we beat the book" may be shown for that tier.
| A record page with units on it reads as exactly that.
|
| Publishing nothing is the correct output of a gate nothing has passed.
| These tests are what keeps it correct.
|
*/

it('does not serve the public record', function () {
    $this->get(route('record'))->assertNotFound();
});

it('does not serve the opinion record', function () {
    $this->get(route('opinions'))->assertNotFound();
});

it('does not serve a pick permalink either', function () {
    $pick = PublishedPick::create([
        'edge_lab_event_id' => 1,
        'sport' => 'nfl', 'market' => 'h2h', 'side' => 'home', 'selection' => 'Chiefs',
        'price_american' => -110,
        'kickoff_at' => now()->addHour(),
        'published_at' => now(),
        'created_at' => now(),
    ]);

    // A permanent address for a claim we are no longer making is just
    // the claim, still being made, one link deeper.
    $this->get(route('record.pick', $pick))->assertNotFound();
});

it('offers no link to a page that is not there', function () {
    $this->get('/')
        ->assertOk()
        ->assertDontSee('Track record')
        ->assertDontSee(route('record'), false);
});

it('leaves the engine off the home page rather than rendering an empty shell', function () {
    // A heading that says what the engine likes today, with nothing
    // under it, is still a claim about what the engine likes today.
    $this->get('/')
        ->assertOk()
        ->assertDontSee('What the engine likes today')
        ->assertDontSee('Public record');
});

it('still serves everything that does not depend on the gate', function (string $route) {
    // The gate governs recommendations. It says nothing about the
    // research tools, the pricing page or how the product works.
    $this->get(route($route))->assertOk();
})->with(['home', 'pricing', 'how-it-works', 'login', 'register']);

it('opens the record the moment a market passes', function () {
    provenMarket();

    // Nothing was deleted and no page was removed - the rows and the
    // routes were waiting on a verdict, which is what makes this a gate
    // rather than a feature being switched off.
    $this->get(route('record'))->assertOk();
    $this->get(route('opinions'))->assertOk();
});
