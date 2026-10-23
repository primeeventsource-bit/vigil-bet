<?php

declare(strict_types=1);

use App\Models\VlEvent;
use App\Models\VlModel;
use App\Models\VlOddsDisplay;
use App\Services\Ai\FactSheet;
use App\Services\Ai\ReadValidator;

/*
|--------------------------------------------------------------------------
| §6: what the fact sheet has to contain
|--------------------------------------------------------------------------
|
| "teams, kickoff, market, side, price and book, fair probability (no-vig),
| model probability, edge, edge at price, verdict, stake fraction, pass
| reason, model version and its gate result, line movement summary,
| availability notes (flagged 'not modelled')".
|
| Listed in the spec because each one is a thing the model would otherwise
| either invent or write around.
|
*/

function eventFor(int $edgeLabId = 77): VlEvent
{
    return VlEvent::create([
        'edge_lab_id' => $edgeLabId,
        'sport' => 'nfl',
        'starts_at' => now()->addDay()->setTime(18, 15),
        'status' => 'scheduled',
        'home_name' => 'Kansas City Chiefs',
        'home_abbreviation' => 'KC',
        'away_name' => 'Buffalo Bills',
        'away_abbreviation' => 'BUF',
        'payload' => [],
        'digest' => 'd1',
        'observed_at' => now(),
    ]);
}

function quote(array $overrides = []): VlOddsDisplay
{
    return VlOddsDisplay::create(array_merge([
        'edge_lab_event_id' => 77,
        'book' => 'draftkings',
        'book_title' => 'DraftKings',
        'market' => 'h2h',
        'side' => 'home',
        'price_american' => -110,
        'quoted_at' => now()->subHours(3),
        'digest' => uniqid(),
        'observed_at' => now(),
    ], $overrides));
}

it('carries the teams and the kickoff, read from the stored event', function () {
    eventFor();

    $sheet = app(FactSheet::class)->forBoardEntry(entryFor([
        'edge_lab_event_id' => 77,
    ]));

    expect($sheet['home_team'])->toBe('Kansas City Chiefs')
        ->and($sheet['away_team'])->toBe('Buffalo Bills')
        ->and($sheet['kickoff'])->not->toBeNull()
        // In words as well, so the model quotes those rather than having
        // the validator treat the "18" in a time as an unsupported claim.
        ->and($sheet['kickoff_written'])->toContain('18:15');
});

it('leaves the teams out rather than guessing when the event is not mirrored', function () {
    $sheet = app(FactSheet::class)->forBoardEntry(entryFor(['edge_lab_event_id' => 999]));

    expect($sheet)->not->toHaveKey('home_team')
        ->and($sheet)->not->toHaveKey('kickoff');
});

it('carries the model version and the gate it was measured against', function () {
    VlModel::create([
        'edge_lab_id' => 5,
        'sport' => 'nfl',
        'market' => 'h2h',
        'model_type' => 'logistic',
        'version' => 4,
        'status' => 'active',
        'verdict' => 'unproven',
        'verdict_reason' => 'Beat the market on log loss but was worse calibrated',
        'allows_picks' => false,
        'predictions' => 1_284,
        'log_loss_improvement' => 0.0231,
        'gate' => ['min_predictions' => 500, 'min_log_loss_improvement' => 0.01],
        'trained_at' => now()->subWeek(),
        'payload' => [],
        'digest' => 'm1',
        'observed_at' => now(),
    ]);

    $sheet = app(FactSheet::class)->forBoardEntry(entryFor());

    expect($sheet['model_version'])->toBe(4)
        ->and($sheet['model_verdict'])->toBe('unproven')
        ->and($sheet['model_verdict_reason'])->toContain('calibrated')
        // The sample size, always: a verdict on 40 games and one on
        // 4,000 are different claims.
        ->and($sheet['model_predictions_measured'])->toBe(1_284)
        ->and($sheet['gate']['min_predictions'])->toBe(500);
});

it('describes how the price moved, with every figure it quotes', function () {
    eventFor();
    quote(['price_american' => -105, 'quoted_at' => now()->subHours(5)]);
    quote(['price_american' => -118, 'quoted_at' => now()->subHour()]);

    $sheet = app(FactSheet::class)->forBoardEntry(entryFor([
        'edge_lab_event_id' => 77,
        'market' => 'h2h',
        'side' => 'home',
        'best_book' => 'draftkings',
    ]));

    expect($sheet['line_movement'])
        ->toContain('DraftKings')
        ->toContain('shortened')
        ->toContain('-105')
        ->toContain('-118');

    // And the validator will accept those numbers in a Read, because the
    // sentence is in the sheet it checks against.
    $allowed = app(ReadValidator::class)->numbersIn($sheet);

    expect($allowed)->toContain('-105')->toContain('-118');
});

it('says there is not enough history rather than calling one quote unchanged', function () {
    eventFor();
    quote();

    $sheet = app(FactSheet::class)->forBoardEntry(entryFor([
        'edge_lab_event_id' => 77,
        'market' => 'h2h',
        'side' => 'home',
        'best_book' => 'draftkings',
    ]));

    // "Unchanged" would be a claim about a history we do not have.
    expect($sheet['line_movement'])->toContain('Not enough quotes');
});

it('reads the direction the right way round', function (int $from, int $to, string $word) {
    eventFor();
    quote(['price_american' => $from, 'quoted_at' => now()->subHours(5)]);
    quote(['price_american' => $to, 'quoted_at' => now()->subHour()]);

    $sheet = app(FactSheet::class)->forBoardEntry(entryFor([
        'edge_lab_event_id' => 77, 'market' => 'h2h', 'side' => 'home', 'best_book' => 'draftkings',
    ]));

    expect($sheet['line_movement'])->toContain($word);
})->with([
    // A price going from -110 to -130 is shorter, not longer, however it
    // looks as an integer.
    [-110, -130, 'shortened'],
    [-130, -110, 'drifted out'],
    [-110, -110, 'held'],
]);

it('names the side as well as the market', function () {
    $sheet = app(FactSheet::class)->forBoardEntry(entryFor(['side' => 'away']));

    expect($sheet['side'])->toBe('away');
});

it('still flags availability as not modelled', function () {
    $sheet = app(FactSheet::class)->forBoardEntry(entryFor());

    // A Read that implied an injury had moved a number would be
    // describing a mechanism that does not exist.
    expect($sheet['injuries_modelled'])->toBeFalse();
});
