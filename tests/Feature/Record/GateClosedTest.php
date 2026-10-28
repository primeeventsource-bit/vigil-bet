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

function archivedPick(): PublishedPick
{
    return PublishedPick::create([
        'edge_lab_event_id' => 1,
        'sport' => 'nfl', 'market' => 'h2h', 'side' => 'home', 'selection' => 'Chiefs',
        'price_american' => -110,
        'kickoff_at' => now()->addHour(),
        'published_at' => now(),
        'created_at' => now(),
    ]);
}

it('does not serve the public record when nothing was ever published', function () {
    // Still a 404, and this is the case the gate was written for: a
    // page of absences is not a record, and an empty table under a
    // heading about a track record is a claim with no rows.
    $this->get(route('record'))->assertNotFound();
});

it('offers no link to a record that is not there', function () {
    $this->get('/')
        ->assertOk()
        ->assertDontSee('Track record')
        ->assertDontSee(route('record'), false);
});

/* ----------------------------------------------------------------------
 | What was published stays readable, labelled for what it is
 ---------------------------------------------------------------------- */

it('serves the record as an archive once picks exist, gate or no gate', function () {
    /*
     * The decision this file used to hold was that no market being
     * proven meant no record page at all. That was right about the
     * claim and wrong about the facts: eight picks had been published
     * before kickoff and graded afterwards, and the gate tightening
     * later does not reach back and unmake the games.
     *
     * So the page opens. What keeps the original promise is the
     * labelling, asserted below.
     */
    archivedPick();

    $this->get(route('record'))->assertOk();
});

it('says it is an archive before it shows a single figure', function () {
    archivedPick();

    $page = $this->get(route('record'))->assertOk()->getContent();

    expect($page)->toContain('This is an archive. Nothing here is being published today.');

    // And the sentence that stops the figures reading as a claim.
    expect($page)->toContain('Read these figures as history, not as a claim.');

    // Said above the numbers, not below them. A caveat under a win rate
    // is a caveat most readers never reach.
    expect(mb_strpos($page, 'This is an archive'))
        ->toBeLessThan(mb_strpos($page, 'Published picks'));
});

it('labels a pick permalink too, since that is where links land', function () {
    $pick = archivedPick();

    $this->get(route('record.pick', $pick))
        ->assertOk()
        ->assertSee('Archived.');
});

it('does not put an archived win rate on the home page', function () {
    /*
     * The one place still gated on the verdict alone. The record page
     * can carry "this is an archive" above its numbers; a hero strip
     * has no room to, and a win rate on a homepage reads as a claim
     * about the product whatever is written beneath it.
     */
    archivedPick();

    $this->get('/')->assertOk()->assertDontSee('Public record');
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
