<?php

declare(strict_types=1);

use App\Models\ParlayGrade;
use App\Models\VigilRead;
use App\Services\EdgeLab\EdgeLabClient;
use App\Services\EdgeLab\EdgeLabException;
use App\Services\Parlay\ParlaySlip;

/**
 * What the judge was asked, so a test can check the question as well as
 * the answer. Static because the stub is built inside a closure the test
 * does not hold a reference to; reset below so it cannot leak between
 * tests.
 */
final class RecordedJudge
{
    /** @var array<int, array<string, mixed>> */
    public static array $legs = [];
}

beforeEach(function () {
    config()->set('ai.enabled', false);
    config()->set('edgelab.enabled', true);
    RecordedJudge::$legs = [];
});

/**
 * A judge that answers in the engine's own shape.
 *
 * `engineParlayVerdict` builds that shape with Present::parlay() from a
 * real ParlayEvaluation, so the field names asserted below are the field
 * names in parlay_evaluations by construction rather than by somebody
 * having typed them correctly. The previous version of this helper faked
 * an HTTP response and invented four field names the engine does not
 * have - see the note at the top of tests/Fixtures/edgelab.php.
 *
 * @param  array<string, mixed>  $overrides  engine columns
 * @param  array<string, mixed>  $extra  fields the product does not read
 */
function judgeReturns(array $overrides = [], array $extra = []): void
{
    $answer = engineParlayVerdict($overrides) + $extra;

    test()->partialMock(EdgeLabClient::class, function ($client) use ($answer) {
        $client->shouldReceive('evaluateParlay')
            ->andReturnUsing(function (array $legs, ?float $shade = null) use ($answer) {
                RecordedJudge::$legs = $legs;

                return $answer;
            });
    });
}

/** A judge that cannot answer at all. */
function judgeUnavailable(string $reason = 'The parlay judge could not price this slip.'): void
{
    test()->partialMock(EdgeLabClient::class, function ($client) use ($reason) {
        $client->shouldReceive('evaluateParlay')->andThrow(new EdgeLabException($reason));
    });
}

it('builds a slip from board picks and keeps the order they were added', function () {
    $ids = slipOf(3);
    $user = betUser();

    foreach ($ids as $id) {
        $this->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $id])
            ->assertSessionHasNoErrors();
    }

    $this->actingAs($user)->get(route('parlays'))
        ->assertOk()
        ->assertSee('Leg 1')
        ->assertSee('Leg 2')
        ->assertSee('Leg 3')
        ->assertSee('3 / '.ParlaySlip::MAX_LEGS);
});

it('refuses a leg from a market that is not proven', function () {
    $entry = provenEntry(['market_verdict' => 'unproven', 'recommendation' => 'pass']);

    $this->actingAs(betUser())->post(route('parlays.add'), ['board_entry_id' => $entry->id])
        ->assertSessionHasErrors('slip');

    // A leg from an unproven market would be graded against a
    // probability the product is not allowed to sell.
    expect(app(ParlaySlip::class)->count())->toBe(0);
});

it('refuses the same selection twice', function () {
    $ids = slipOf(1);
    $user = betUser();

    $this->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $ids[0]]);

    $this->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $ids[0]])
        ->assertSessionHasErrors('slip');

    expect(session('errors')->first('slip'))->toContain('already on the slip');
});

it('refuses two sides of the same market', function () {
    $user = betUser();

    $home = provenEntry(['id' => 1, 'edge_lab_event_id' => 500, 'market' => 'h2h', 'side' => 'home', 'selection' => 'Chiefs']);
    $away = provenEntry(['id' => 2, 'edge_lab_event_id' => 500, 'market' => 'h2h', 'side' => 'away', 'selection' => 'Bills']);

    $this->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $home->id]);

    $this->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $away->id])
        ->assertSessionHasErrors('slip');

    // Not a correlation. Two sides of one market cannot both win, and a
    // judge asked to price that would be answering nonsense.
    expect(session('errors')->first('slip'))->toContain('cannot both win');
});

it('caps the slip length', function () {
    $ids = slipOf(ParlaySlip::MAX_LEGS + 1);
    $user = betUser();

    foreach ($ids as $i => $id) {
        $response = $this->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $id]);

        if ($i === ParlaySlip::MAX_LEGS) {
            $response->assertSessionHasErrors('slip');
            expect(session('errors')->first('slip'))->toContain('hold swamps');
        }
    }

    expect(app(ParlaySlip::class)->count())->toBe(ParlaySlip::MAX_LEGS);
});

it('will not grade a slip with fewer than two legs', function () {
    $ids = slipOf(1);
    $user = betUser();

    $this->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $ids[0]]);

    $this->actingAs($user)->post(route('parlays.grade'))->assertSessionHasErrors('slip');

    expect(ParlayGrade::count())->toBe(0);
});

/* ----------------------------------------------------------------------
 | The judge
 ---------------------------------------------------------------------- */

it('stores what the engine said, verbatim, and never computes it here', function () {
    judgeReturns(
        [
            'model_joint_prob' => 0.186,
            'fair_joint_prob' => 0.1856,
            'offered_price_american' => 380,
            'hold' => 0.0925,
            'ev_shaded' => -0.041,
            'recommendation' => 'pass',
        ],
        // A field the product knows nothing about. It still has to survive,
        // because a grade reproduced from a subset of its own fields is not
        // the grade the customer saw.
        ['correlation_adjustment' => 0.014],
    );

    $ids = slipOf(3);
    $user = betUser();

    foreach ($ids as $id) {
        $this->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $id]);
    }

    $this->actingAs($user)->post(route('parlays.grade'))->assertSessionHasNoErrors();

    $grade = ParlayGrade::sole();

    expect($grade->leg_count)->toBe(3)
        ->and($grade->win_probability)->toBe(0.186)
        ->and($grade->book_hold)->toBe(0.0925)
        ->and($grade->shaded_edge)->toBe(-0.041)
        ->and($grade->verdict)->toBe('pass')
        ->and($grade->isWorthBacking())->toBeFalse()
        /*
         * Plus money, because the fair probability is well under a half.
         * Asserted as a property rather than as a number: the engine owns
         * the conversion from its stored probability to a price, and a
         * literal here would just be that conversion typed out again.
         */
        ->and($grade->fair_price_american)->toBeGreaterThan(0)
        ->and($grade->result['correlation_adjustment'])->toBe(0.014);
});

it('sends one leg per selection in the shape the engine prices', function () {
    judgeReturns(['model_joint_prob' => 0.2, 'hold' => 0.08, 'ev_shaded' => 0.01]);

    $ids = slipOf(2);
    $user = betUser();

    foreach ($ids as $id) {
        $this->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $id]);
    }

    $this->actingAs($user)->post(route('parlays.grade'));

    expect(RecordedJudge::$legs)->toHaveCount(2)
        ->and(RecordedJudge::$legs[0])->toHaveKeys(['event_id', 'market', 'side']);
});

it('records that the judge could not be reached rather than calling the slip worthless', function () {
    judgeUnavailable();

    $ids = slipOf(2);
    $user = betUser();

    foreach ($ids as $id) {
        $this->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $id]);
    }

    $this->actingAs($user)->post(route('parlays.grade'));

    $grade = ParlayGrade::sole();

    expect($grade->wasAnswered())->toBeFalse()
        ->and($grade->unavailable_reason)->not->toBeNull()
        ->and($grade->shaded_edge)->toBeNull();

    // And the page says so, as a failure to answer rather than a verdict.
    $this->actingAs($user)->get(route('parlays'))
        ->assertOk()
        ->assertSee('could not be reached')
        ->assertSee('That is not');
});

it('writes a read explaining the grade', function () {
    judgeReturns([
        'model_joint_prob' => 0.186,
        'fair_joint_prob' => 0.1856,
        'offered_price_american' => 380,
        'hold' => 0.0925,
        'ev_shaded' => -0.041,
    ]);

    $ids = slipOf(2);
    $user = betUser();

    foreach ($ids as $id) {
        $this->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $id]);
    }

    $this->actingAs($user)->post(route('parlays.grade'));

    $read = VigilRead::where('subject_type', 'parlay')->sole();

    // The hold leads, because it is the figure that decides the question
    // and the one a customer has almost certainly never been shown.
    expect($read->body)->toContain('The book keeps')
        ->and($read->body)->toContain('9.3%')
        ->and($read->fact_sheet['kind'])->toBe('parlay');
});

it('does not let a read describe an ungraded slip as a bad bet', function () {
    judgeUnavailable();

    $ids = slipOf(2);
    $user = betUser();

    foreach ($ids as $id) {
        $this->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $id]);
    }

    $this->actingAs($user)->post(route('parlays.grade'));

    $read = VigilRead::where('subject_type', 'parlay')->sole();

    expect($read->body)->toContain('went unanswered')
        ->and($read->body)->not->toContain('edge on this slip');
});

it('never rewrites a grade once it is stored', function () {
    judgeReturns(['win_probability' => 0.2, 'hold' => 0.08, 'shaded_edge' => 0.01]);

    $ids = slipOf(2);
    $user = betUser();

    foreach ($ids as $id) {
        $this->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $id]);
    }

    $this->actingAs($user)->post(route('parlays.grade'));

    expect(fn () => ParlayGrade::sole()->update(['shaded_edge' => 0.99]))
        ->toThrow(RuntimeException::class, 'append-only');
});

it('grades three a day on the free plan and says when that is used up', function () {
    judgeReturns(['win_probability' => 0.2, 'hold' => 0.08, 'shaded_edge' => 0.01]);

    $ids = slipOf(2);
    $user = betUser(null);

    foreach ($ids as $id) {
        $this->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $id]);
    }

    foreach (range(1, 3) as $i) {
        $this->actingAs($user)->post(route('parlays.grade'))->assertSessionHasNoErrors();
    }

    $this->actingAs($user)->post(route('parlays.grade'))->assertSessionHasErrors('slip');

    // Counted against stored grades, which are append-only - a limit
    // enforced against a session counter is not a limit.
    expect(ParlayGrade::count())->toBe(3);
    expect(session('errors')->first('slip'))->toContain('still here tomorrow');
});

it('does not limit a subscriber', function () {
    judgeReturns(['win_probability' => 0.2, 'hold' => 0.08, 'shaded_edge' => 0.01]);

    $ids = slipOf(2);
    $user = betUser('daily');

    foreach ($ids as $id) {
        $this->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $id]);
    }

    foreach (range(1, 6) as $i) {
        $this->actingAs($user)->post(route('parlays.grade'))->assertSessionHasNoErrors();
    }

    expect(ParlayGrade::count())->toBe(6);
});

it('clears and removes legs', function () {
    $ids = slipOf(2);
    $user = betUser();

    foreach ($ids as $id) {
        $this->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $id]);
    }

    $this->actingAs($user)->delete(route('parlays.remove'), ['board_entry_id' => $ids[0]]);
    expect(app(ParlaySlip::class)->count())->toBe(1);

    $this->actingAs($user)->delete(route('parlays.clear'));
    expect(app(ParlaySlip::class)->count())->toBe(0);
});
