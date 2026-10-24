<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\TeamAlias;
use App\Models\UnmatchedName;

it('links a name when exactly one team matches', function () {
    college('Duke Blue Devils');
    unmatched('Duke');

    $this->artisan('edge:teams:resolve', ['--apply' => true])->assertSuccessful();

    expect(TeamAlias::where('alias_raw', 'Duke')->exists())->toBeTrue()
        ->and(UnmatchedName::count())->toBe(0);
});

it('refuses to guess when two teams could match', function () {
    // A wrong alias attaches odds to the wrong team permanently, so an
    // ambiguous name is left for a human rather than resolved on a hunch.
    college('Miami Hurricanes');
    college('Miami RedHawks');
    unmatched('Miami');

    $this->artisan('edge:teams:resolve', ['--apply' => true])->assertSuccessful();

    expect(TeamAlias::where('alias_raw', 'Miami')->exists())->toBeFalse()
        ->and(UnmatchedName::count())->toBe(1);
});

it('leaves a name with no candidate alone', function () {
    college('Duke Blue Devils');
    unmatched('Somewhere Polytechnic');

    $this->artisan('edge:teams:resolve', ['--apply' => true])->assertSuccessful();

    expect(UnmatchedName::count())->toBe(1);
});

it('reports without writing unless asked', function () {
    college('Duke Blue Devils');
    unmatched('Duke');

    $this->artisan('edge:teams:resolve')->assertSuccessful();

    expect(TeamAlias::where('alias_raw', 'Duke')->exists())->toBeFalse()
        ->and(UnmatchedName::count())->toBe(1);
});

it('does not cross sports', function () {
    college('Duke Blue Devils', 'ncaab');
    unmatched('Duke', 'ncaaf');

    $this->artisan('edge:teams:resolve', ['--apply' => true])->assertSuccessful();

    expect(TeamAlias::count())->toBe(0);
});

it('ignores short filler words when matching', function () {
    college('Saint Johns Red Storm');
    unmatched('St. Johns');

    $this->artisan('edge:teams:resolve', ['--apply' => true])->assertSuccessful();

    // "St." normalises to "saint" and "johns" is significant, so this is a
    // single credible match rather than a guess.
    expect(TeamAlias::where('alias_raw', 'St. Johns')->exists())->toBeTrue();
});
