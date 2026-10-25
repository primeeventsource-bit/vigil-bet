<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Saving a parlay
|--------------------------------------------------------------------------
|
| The distinction the whole feature turns on: a parlay the user built is
| not a wager until they say it is. The spec says so outright - do not
| represent a saved parlay as real money unless the user records it as
| placed - and these tests are where that holds, because the difference
| between a draft and a placed bet is a bankroll entry.
|
*/

use App\Models\BankrollEntry;
use App\Models\User;
use App\Models\UserParlay;
use App\Services\Parlay\ParlayLedger;

function twoLegs(): array
{
    return [
        [
            'sport' => 'nfl', 'market' => 'h2h', 'side' => 'home',
            'selection' => 'Baltimore Ravens', 'price_american' => 100,
        ],
        [
            'sport' => 'nfl', 'market' => 'spreads', 'side' => 'away', 'line' => -3.5,
            'selection' => 'Miami Dolphins -3.5', 'price_american' => 100,
        ],
    ];
}

function bankrollEntriesFor(User $user): object
{
    return (object) [
        'stakes' => BankrollEntry::query()->where('user_id', $user->id)
            ->where('kind', BankrollEntry::STAKE)->get(),
        'returns' => BankrollEntry::query()->where('user_id', $user->id)
            ->where('kind', BankrollEntry::RETURN_)->get(),
    ];
}

it('saves a built parlay as a draft, which moves no money', function (): void {
    $user = User::factory()->create();

    $parlay = app(ParlayLedger::class)->save($user, twoLegs(), ['stake_cents' => 5000]);

    expect($parlay->status)->toBe('draft')
        ->and($parlay->placed_at)->toBeNull()
        ->and($parlay->legs)->toHaveCount(2)
        ->and(bankrollEntriesFor($user)->stakes)->toHaveCount(0);
});

it('takes the stake out of the bankroll the moment a parlay is recorded as placed', function (): void {
    /*
     * At placement rather than settlement, as a straight bet does it:
     * money on a running bet is gone until it is not, and a bankroll that
     * only moved at settlement would overstate what is available.
     */
    $user = User::factory()->create();

    $parlay = app(ParlayLedger::class)->save($user, twoLegs(), [
        'stake_cents' => 5000,
        'placed' => true,
    ]);

    $stakes = bankrollEntriesFor($user)->stakes;

    expect($parlay->status)->toBe('pending')
        ->and($parlay->placed_at)->not->toBeNull()
        ->and($stakes)->toHaveCount(1)
        ->and($stakes->first()->amount_cents)->toBe(-5000)
        ->and($stakes->first()->user_parlay_id)->toBe($parlay->id);
});

it('keeps a paper parlay away from the bankroll entirely', function (): void {
    /*
     * Recording practice stakes against a real balance would ruin the one
     * figure a customer actually checks, and trying something without
     * that cost is the whole point of allowing paper bets.
     */
    $user = User::factory()->create();

    $parlay = app(ParlayLedger::class)->save($user, twoLegs(), [
        'stake_cents' => 5000,
        'placed' => true,
        'is_paper' => true,
    ]);

    expect($parlay->status)->toBe('pending')
        ->and($parlay->is_paper)->toBeTrue()
        ->and(bankrollEntriesFor($user)->stakes)->toHaveCount(0);
});

it('promotes a draft to a placed bet and debits then, not before', function (): void {
    $user = User::factory()->create();
    $ledger = app(ParlayLedger::class);

    $parlay = $ledger->save($user, twoLegs(), ['stake_cents' => 5000]);
    expect(bankrollEntriesFor($user)->stakes)->toHaveCount(0);

    $placed = $ledger->place($parlay, [
        'accepted_price_american' => 260,
        'book' => 'draftkings',
        'ticket_reference' => 'DK-12345',
    ]);

    expect($placed->status)->toBe('pending')
        ->and($placed->accepted_price_american)->toBe(260)
        ->and($placed->ticket_reference)->toBe('DK-12345')
        ->and(bankrollEntriesFor($user)->stakes)->toHaveCount(1);
});

it('does not debit twice when a parlay is placed again', function (): void {
    $user = User::factory()->create();
    $ledger = app(ParlayLedger::class);

    $parlay = $ledger->save($user, twoLegs(), ['stake_cents' => 5000, 'placed' => true]);
    $ledger->place($parlay->fresh());

    expect(bankrollEntriesFor($user)->stakes)->toHaveCount(1);
});

it('computes the combined price from the legs', function (): void {
    /*
     * Kept beside the accepted price rather than instead of it. Two even
     * legs are decimal 4.0 together, which is +300 - and a book offering
     * +260 for the same parlay is the margin, which is worth being able
     * to see.
     */
    $user = User::factory()->create();

    $parlay = app(ParlayLedger::class)->save($user, twoLegs(), [
        'stake_cents' => 5000,
        'accepted_price_american' => 260,
    ]);

    expect($parlay->computed_price_american)->toBe(300)
        ->and($parlay->accepted_price_american)->toBe(260);
});

it('freezes what the engine thought when the leg was saved', function (): void {
    /*
     * Models retrain weekly. Read live, every past comparison would be
     * rewritten each Sunday, and a comparison that changes after the game
     * is not a comparison.
     */
    $user = User::factory()->create();

    $legs = twoLegs();
    $legs[0]['model_probability'] = 0.58;
    $legs[0]['model_version'] = 'nfl-elo-2026-10-19';
    $legs[0]['model_edge'] = 0.041;
    $legs[0]['model_agreement'] = 'agreed';

    $leg = app(ParlayLedger::class)->save($user, $legs, ['stake_cents' => 5000])
        ->legs->firstWhere('selection', 'Baltimore Ravens');

    expect($leg->model_probability)->toBe(0.58)
        ->and($leg->model_version)->toBe('nfl-elo-2026-10-19')
        ->and($leg->model_edge)->toBe(0.041)
        ->and($leg->model_agreement)->toBe('agreed');
});

it('refuses a single selection, which is a straight bet and already has a home', function (): void {
    $user = User::factory()->create();

    expect(fn () => app(ParlayLedger::class)->save($user, [twoLegs()[0]], ['stake_cents' => 5000]))
        ->toThrow(RuntimeException::class);
});

it('refuses a parlay with no stake, because nothing could be measured from it', function (): void {
    $user = User::factory()->create();

    expect(fn () => app(ParlayLedger::class)->save($user, twoLegs(), ['stake_cents' => 0]))
        ->toThrow(RuntimeException::class);
});

it('records what happened, in order, for every parlay', function (): void {
    $user = User::factory()->create();
    $ledger = app(ParlayLedger::class);

    $parlay = $ledger->save($user, twoLegs(), ['stake_cents' => 5000]);
    $ledger->place($parlay->fresh());

    expect($parlay->fresh()->events->pluck('type')->all())->toBe(['drafted', 'placed']);
});

it('belongs to the user who saved it and nobody else', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    app(ParlayLedger::class)->save($mine, twoLegs(), ['stake_cents' => 5000]);

    expect(UserParlay::query()->where('user_id', $theirs->id)->count())->toBe(0)
        ->and(UserParlay::query()->where('user_id', $mine->id)->count())->toBe(1);
});
