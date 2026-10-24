<?php

declare(strict_types=1);

use App\Enums\InjuryStatus;

/*
 * The label vocabularies here were read off live responses for all six
 * sports rather than guessed at, which is the only reason this mapping can
 * be trusted: every league publishes its own wording and changes it without
 * telling anyone.
 */

it('maps the NFL vocabulary', function (string $label, InjuryStatus $expected) {
    expect(InjuryStatus::fromSourceLabel($label))->toBe($expected);
})->with([
    ['Questionable', InjuryStatus::Questionable],
    ['Doubtful', InjuryStatus::Doubtful],
    ['Out', InjuryStatus::Out],
    ['Active', InjuryStatus::Active],
    ['Injured Reserve', InjuryStatus::Out],
    ['Suspension', InjuryStatus::Out],
]);

it('maps the NBA and NHL vocabulary', function (string $label, InjuryStatus $expected) {
    expect(InjuryStatus::fromSourceLabel($label))->toBe($expected);
})->with([
    ['Day-To-Day', InjuryStatus::Questionable],
    ['Out', InjuryStatus::Out],
    ['Injured Reserve', InjuryStatus::Out],
]);

it('maps every flavour of the MLB injured list to unavailable', function (string $label) {
    // 7, 10, 15 and 60 day lists, and whatever length they invent next.
    expect(InjuryStatus::fromSourceLabel($label))->toBe(InjuryStatus::Out);
})->with([
    ['15-Day-IL'],
    ['60-Day-IL'],
    ['10-Day-IL'],
    ['7-Day IL'],
    ['21-day-il'],
    ['suspension'],
    ['paternity'],
]);

it('does not guess at a label it has never seen', function () {
    // Mapping an unknown label to Active would declare a possibly-absent
    // player fit; mapping it to Out would bench a possibly-fit one.
    expect(InjuryStatus::fromSourceLabel('Reconditioning'))->toBe(InjuryStatus::Unknown)
        ->and(InjuryStatus::fromSourceLabel(''))->toBe(InjuryStatus::Unknown)
        ->and(InjuryStatus::fromSourceLabel(null))->toBe(InjuryStatus::Unknown);
});

it('ignores the casing and spacing a feed happens to use', function () {
    expect(InjuryStatus::fromSourceLabel('  OUT  '))->toBe(InjuryStatus::Out)
        ->and(InjuryStatus::fromSourceLabel('day to day'))->toBe(InjuryStatus::Questionable);
});

it('gives no availability number for a status it does not understand', function () {
    // A number here would look like an answer. The caller has to decide
    // what to do about not knowing.
    expect(InjuryStatus::Unknown->availability())->toBeNull()
        ->and(InjuryStatus::Out->availability())->toBe(0.0)
        ->and(InjuryStatus::Active->availability())->toBe(1.0);
});

it('orders availability the way the statuses actually rank', function () {
    $ranked = [
        InjuryStatus::Out,
        InjuryStatus::Doubtful,
        InjuryStatus::Questionable,
        InjuryStatus::Probable,
        InjuryStatus::Active,
    ];

    $previous = -1.0;

    foreach ($ranked as $status) {
        expect($status->availability())->toBeGreaterThan($previous);
        $previous = $status->availability();
    }
});
