<?php

declare(strict_types=1);

use App\Models\Opinion;
use App\Models\PublishedPick;
use App\Services\Record\OpinionPublisher;

/*
|--------------------------------------------------------------------------
| Two gates, and the difference between them
|--------------------------------------------------------------------------
|
| The engine's own predictions are the product. They are published once a
| market clears OpinionBar - its stated probabilities have been measured
| and they hold - and they are careful never to claim an edge over the
| closing line.
|
| The public record claims exactly that, and it is a different and much
| stronger thing. Its gate is the verdict, and no market has ever passed
| it: the live verdicts are calibrated, unproven, negative and
| insufficient_data, not one proven among them. The site carried a track
| record with a win rate and units anyway, because `Calibrated` was
| reaching the pick publisher.
|
| So one gate is shut and the other is open, and these tests are what
| keeps them from being confused for each other again - in either
| direction. Hiding the engine behind the verdict gate would be the same
| mistake with the sign flipped: the product withheld against a standard
| it never claimed to meet.
|
*/

it('does not serve the public record while no market has beaten the close', function () {
    $this->get(route('record'))->assertNotFound();
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

it('offers no link to a record that is not there', function () {
    $this->get('/')
        ->assertOk()
        ->assertDontSee('Track record')
        ->assertDontSee(route('record'), false);
});

/* ----------------------------------------------------------------------
 | The engine still shows, because it answers to the other gate
 ---------------------------------------------------------------------- */

it('serves the engine record even though no market has been proven', function () {
    opinionCandidate();
    app(OpinionPublisher::class)->run();

    expect(Opinion::anyPublished())->toBeTrue();

    $this->get(route('opinions'))->assertOk();
});

it('shows what the engine published on the home page', function () {
    opinionCandidate();
    app(OpinionPublisher::class)->run();

    $this->get('/')->assertOk()->assertSee('What the engine likes today');
});

it('leaves the engine off the home page when it has published nothing', function () {
    // A heading that says what the engine likes today, over nothing, is
    // still a claim about what the engine likes today.
    $this->get('/')
        ->assertOk()
        ->assertDontSee('What the engine likes today')
        ->assertDontSee('Public record');
});

it('serves the engine record empty rather than hiding it', function () {
    // Not gated at all. An accuracy page with no rows says there is
    // nothing to judge yet, which is true and checkable; a 404 says
    // nothing. The gate that matters guards the claim, not the page.
    $this->get(route('opinions'))->assertOk();
});

it('still serves everything that answers to neither gate', function (string $route) {
    // Research tools, pricing and how the product works do not depend on
    // what any market has or has not proved.
    $this->get(route($route))->assertOk();
})->with(['home', 'pricing', 'how-it-works', 'login', 'register']);

it('opens the public record the moment a market passes', function () {
    provenMarket();

    // Nothing was deleted and no route was removed - they were waiting
    // on a verdict, which is what makes this a gate rather than a
    // feature switched off.
    $this->get(route('record'))->assertOk();
});
