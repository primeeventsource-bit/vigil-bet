<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/*
|--------------------------------------------------------------------------
| The labelled bar
|--------------------------------------------------------------------------
|
| It printed every value as a percentage, which is right for a rate and
| silently wrong for a count. Three callers were passing counts, so the
| board reported 1,769,200 candidates declined for no price out of 23,364
| judged, and the admin overview multiplied its customers by a hundred.
|
| Nothing failed. The numbers were simply a hundred times too big, on a
| page whose whole job is to explain where the candidates went.
|
*/

it('prints a rate as a percentage', function () {
    $html = Blade::render('<x-chart.bar-row label="NHL" :value="0.62" />');

    expect($html)->toContain('62%');
});

it('prints a count as a count', function () {
    // The bug, in one assertion: 17,692 is not 1,769,200.
    $html = Blade::render('<x-chart.bar-row label="no price" :value="17692" :max="17692" format="count" />');

    expect($html)->toContain('17,692')
        ->and($html)->not->toContain('1,769,200');
});

it('draws the bar from the row scale, whichever format it is', function () {
    // Half of the maximum is half a bar, for a count as for a rate.
    $html = Blade::render('<x-chart.bar-row label="x" :value="50" :max="100" format="count" />');

    expect($html)->toContain('width: 50%');
});

it('shows a dash rather than a zero when there is no value', function () {
    // Same rule as everywhere else in this product: an unmeasured
    // figure and a measured zero are different claims.
    $html = Blade::render('<x-chart.bar-row label="x" :value="null" />');

    expect($html)->toContain('—')
        ->and($html)->not->toContain('0%');
});
