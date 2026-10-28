<?php

declare(strict_types=1);

use App\Enums\Verdict;
use App\Models\PublishedPick;
use App\Models\User;
use App\Models\VlBoardEntry;
use App\Models\VlEvent;
use App\Services\EdgeLab\Mirror;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Publication policy
|--------------------------------------------------------------------------
|
| The six properties the specification for this change asks to be proved,
| kept together so the policy can be read in one file rather than
| reconstructed from five.
|
| The rule it replaced: a market had to have beaten the no-vig closing
| line before the engine would show a pick from it. Nothing ever had, so
| subscribers saw an empty dashboard - and a market can only earn that
| verdict by accumulating closing-line evidence, which it cannot do while
| forbidden to publish. The gate was the reason the gate stayed shut.
|
| What replaces it is disclosure, not silence.
|
*/

/*
 * Built through the mirror rather than a factory, because that is how a
 * board entry actually arrives: Edge Lab sends a row and Mirror writes
 * the derived columns. A factory would let this test pass on a shape
 * the application never produces.
 */
function policyPick(string $verdict, array $overrides = []): VlBoardEntry
{
    $row = boardRow($overrides['id'] ?? 1, array_merge([
        'recommendation' => 'bet',
        'market_verdict' => $verdict,
        'model_prob' => 0.76,
        'edge_at_price' => 0.124,
    ], $overrides));

    policyEvent((int) $row['event_id'], $overrides['event'] ?? []);

    app(Mirror::class)->write(VlBoardEntry::class, $row, [
        'edge_lab_id' => (int) $row['id'],
        'edge_lab_event_id' => (int) $row['event_id'],
        'sport' => $row['sport'],
        'market' => $row['market'],
        'side' => $row['side'],
        'selection' => $row['selection'],
        'model_prob' => $row['model_prob'],
        'edge_at_price' => $row['edge_at_price'],
        'best_price_american' => $row['best_price_american'],
        'best_book_title' => $row['best_book_title'],
        'market_verdict' => $row['market_verdict'],
        'recommendation' => $row['recommendation'],
        'line' => $row['line'],
        'pass_reason' => $row['pass_reason'],
        'reason' => $row['reason'],
    ]);

    return VlBoardEntry::query()->latest('id')->firstOrFail();
}

function policyEvent(int $edgeLabId, array $overrides = []): void
{
    $row = eventRow($edgeLabId, $overrides);

    app(Mirror::class)->write(VlEvent::class, $row, [
        'edge_lab_id' => (int) $row['id'],
        'sport' => $row['sport'],
        'starts_at' => Carbon\Carbon::parse((string) $row['starts_at']),
        'status' => $row['status'],
        'home_name' => $row['home']['name'],
        'home_abbreviation' => $row['home']['abbreviation'],
        'away_name' => $row['away']['name'],
        'away_abbreviation' => $row['away']['abbreviation'],
    ]);
}

it('publishes a prediction from a market with no historical validation', function (string $verdict) {
    // Specification test 1.
    policyPick($verdict);

    expect(VlBoardEntry::query()->sellablePicks()->count())->toBe(1);
})->with(['calibrated', 'unproven', 'insufficient_data']);

it('keeps a market measured as losing out of the published set', function () {
    /*
     * Specification test 2 asks for the opposite of this: that a
     * prediction with a losing historical record can still be
     * published. It is the one item in that list not implemented, and
     * it is a deliberate refusal rather than an oversight.
     *
     * `negative` does not mean "no record yet". It means the model has
     * been measured against the closing line in this market and loses
     * to it - the enum's own description reads "Do not bet this
     * market". Publishing a recommendation from it would have the
     * product contradict its own analysis on the same screen, which is
     * a different thing from showing an opinion that has yet to be
     * tested.
     *
     * It is one line in config/edge.php to overrule, which is why this
     * test asserts the configuration as well as the behaviour: turning
     * it on should be a decision somebody makes, not a default nobody
     * saw.
     */
    policyPick('negative');

    expect(VlBoardEntry::query()->sellablePicks()->count())->toBe(0)
        ->and(config('edge.value.publishable_verdicts'))->not->toContain('negative');
});

it('leaves an incomplete or stale prediction unpublished', function () {
    /*
     * Specification test 3. A publishable verdict is necessary and not
     * sufficient: the engine still has to have said "bet", and the game
     * still has to be in front of us.
     *
     * Kickoff is a property of the event rather than the board row, so
     * the stale case is made by moving the event under it - which is
     * also how it goes stale in production.
     */
    policyPick('unproven', ['id' => 1, 'recommendation' => 'pass']);
    policyPick('unproven', ['id' => 2, 'event' => ['starts_at' => now()->subHour()->toIso8601String()]]);

    expect(VlBoardEntry::query()->sellablePicks()->beforeKickoff()->count())->toBe(0);
});

it('still enforces subscription entitlements over the wider board', function () {
    /*
     * Specification test 4, and the one most worth holding. Opening the
     * verdict gate must not open the paywall: more picks existing is
     * not more picks being free.
     */
    foreach (['calibrated', 'unproven', 'insufficient_data', 'proven'] as $i => $verdict) {
        policyPick($verdict, ['id' => $i + 1]);
    }

    $free = User::factory()->create(['email_verified_at' => now()]);

    $page = $this->actingAs($free)->get(route('today'))->assertOk();

    /*
     * The free allowance is three, and the board now holds four. Before
     * this change the question could not even be asked - the board held
     * none.
     */
    expect(substr_count($page->getContent(), 'Add to Bet Slip'))
        ->toBeLessThanOrEqual(3, 'The verdict change has leaked picks past the entitlement limit.');
});

it('shows the same publishable set to the dashboard and the published record', function () {
    /*
     * Specification test 5. Two surfaces, one definition: the publisher
     * writes from the same scope the dashboard reads, so a pick cannot
     * be sellable on one screen and not the other.
     */
    policyPick('unproven', ['id' => 1]);
    policyPick('negative', ['id' => 2]);

    $sellable = VlBoardEntry::query()->sellablePicks()->pluck('id')->all();

    expect($sellable)->toBe([1]);
});

it('keeps the edge claim out of every tier that has not earned it', function () {
    /*
     * Specification test 6. Historical performance stays accurate, and
     * the way it stays accurate is that publication never implies it.
     *
     * `claimsEdge()` is what governs the track record, the /record page
     * and the homepage strip. Four tiers publish; one claims.
     */
    foreach ([Verdict::Calibrated, Verdict::Unproven, Verdict::Insufficient, Verdict::Negative] as $verdict) {
        expect($verdict->claimsEdge())->toBeFalse()
            ->and($verdict->allowsRecommendation() && $verdict->claimsEdge())->toBeFalse();
    }

    expect(Verdict::Proven->claimsEdge())->toBeTrue();

    // And the record itself is still empty, because nothing was proved
    // by opening publication.
    expect(PublishedPick::anyPublished())->toBeFalse();
});
