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
    expect(Verdict::Proven->allowsRecommendation())->toBeTrue()
        ->and(Verdict::Calibrated->allowsRecommendation())->toBeTrue()
        ->and(Verdict::Unproven->allowsRecommendation())->toBeFalse()
        ->and(Verdict::Negative->allowsRecommendation())->toBeFalse()
        ->and(Verdict::Insufficient->allowsRecommendation())->toBeFalse();
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
