<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Nothing decides for itself which markets may publish
|--------------------------------------------------------------------------
|
| The right to surface a pick used to be the word 'proven', written out
| in five separate places. Adding a second tier meant changing all five,
| and the fifth was missed: `SyncModels` mirrored every calibrated market
| with `allows_picks` false, so the engine approved a market and the board
| went on refusing it. Nothing failed; the two halves just disagreed.
|
| So the question is asked in one place - `Verdict::allowsRecommendation()`
| - and this test exists to keep it that way. A sixth copy of the string
| fails here rather than in production, silently, three screens apart.
|
*/

use App\Enums\Verdict;
use App\Models\VlBoardEntry;

/** @return array<int, string> */
function sourceFilesGating(): array
{
    $roots = [app_path()];
    $files = [];

    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

it('asks one question about which verdicts may publish', function () {
    /*
     * The set is configuration now, not a fixed tier. It was `Proven`
     * alone, which meant the engine could show a pick from no market at
     * all - nothing has beaten the closing line - so the dashboard, the
     * board and the parlay builder were empty for subscribers, and the
     * one tier that could open the gate could only be earned by
     * publishing through it.
     *
     * What stays fixed is that the question is asked once.
     */
    expect(Verdict::Proven->allowsRecommendation())->toBeTrue()
        ->and(Verdict::Calibrated->allowsRecommendation())->toBeTrue()
        ->and(Verdict::Unproven->allowsRecommendation())->toBeTrue()
        ->and(Verdict::Insufficient->allowsRecommendation())->toBeTrue();
});

it('still refuses the one tier that was measured and found wanting', function () {
    /*
     * `negative` is not an unvalidated market. It is a validated one
     * whose finding is that the model loses to the price. Publishing a
     * recommendation from it would not be showing an unproven opinion,
     * it would be showing one already measured as bad - which is a
     * different thing from the historical-profitability gate this
     * release removed.
     *
     * It is configuration, so this is overridable in one line. It is
     * tested so that overriding it is a decision rather than an
     * accident.
     */
    expect(Verdict::Negative->allowsRecommendation())->toBeFalse()
        ->and(config('edge.value.publishable_verdicts'))->not->toContain('negative');
});

it('never lets publication become a claim of edge', function () {
    /*
     * The boundary this whole release turns on, and the one thing that
     * must not move.
     *
     * Four tiers may now publish. Exactly one may claim to beat the
     * closing line, and it is the one that has. If these two sets ever
     * become the same set, the product is telling customers that an
     * unvalidated market beat the book - which is the failure the
     * Verdict docblock records happening once already.
     */
    $publishable = array_filter(Verdict::cases(), fn (Verdict $v): bool => $v->allowsRecommendation());
    $claiming = array_filter(Verdict::cases(), fn (Verdict $v): bool => $v->claimsEdge());

    expect($claiming)->toHaveCount(1)
        ->and(Verdict::Proven->claimsEdge())->toBeTrue()
        ->and(count($publishable))->toBeGreaterThan(count($claiming),
            'Publication and the edge claim have collapsed into one set.');

    foreach ([Verdict::Calibrated, Verdict::Unproven, Verdict::Insufficient, Verdict::Negative] as $verdict) {
        expect($verdict->claimsEdge())->toBeFalse();
    }
});

it('derives the publishable list from the enum rather than restating it', function () {
    $fromEnum = array_values(array_map(
        static fn (Verdict $v): string => $v->value,
        array_filter(Verdict::cases(), static fn (Verdict $v): bool => $v->allowsRecommendation()),
    ));

    expect(VlBoardEntry::recommendableVerdicts())->toBe($fromEnum);
});

it('has no gate left comparing against the word proven', function () {
    /*
     * Equality against 'proven' is the shape of the bug: a gate that
     * admits one tier and silently excludes any other. Counting it
     * anywhere in app/ is cheaper than finding out from a board that
     * refuses what the engine approved.
     *
     * Reading a verdict to *display* it is fine. Deciding with it is not,
     * so only comparisons are matched.
     */
    $offenders = [];

    foreach (sourceFilesGating() as $path) {
        $source = (string) file_get_contents($path);

        foreach (preg_split('/\R/', $source) ?: [] as $number => $line) {
            if (preg_match("/(===|==|!==|!=)\s*'proven'|'proven'\s*(===|==|!==|!=)/", $line) === 1) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'Gates comparing against the string: '.implode(', ', $offenders));
});

it('has no query filtering a verdict column to proven alone', function () {
    $offenders = [];

    foreach (sourceFilesGating() as $path) {
        $source = (string) file_get_contents($path);

        foreach (preg_split('/\R/', $source) ?: [] as $number => $line) {
            if (preg_match("/where\(\s*'(market_)?verdict'\s*,\s*'proven'/", $line) === 1) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'Queries pinned to one tier: '.implode(', ', $offenders));
});
