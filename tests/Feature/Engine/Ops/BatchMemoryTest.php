<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The memory ceiling for batch work
|--------------------------------------------------------------------------
|
| Laravel Cloud sets `memory_limit` to 256M inside a container the cgroup
| allows 1024M. Three quarters of the allowance was unreachable by any one
| process: `ingest:box-scores mlb --limit=1200` died on PHP's limit while
| the container had 768M spare, and every backfill since has run in chunks
| of 250 to stay under it.
|
| The managed ini cannot be edited to any lasting effect, so the limit is
| raised at runtime for batch work only. These tests are mostly about the
| ways that could go wrong: lowering a limit somebody deliberately raised,
| or comparing "512M" against "64M" as strings.
|
*/

use App\Support\BatchMemory;

afterEach(function (): void {
    ini_set('memory_limit', '-1');
});

it('reads ini sizes as sizes, not as strings', function (): void {
    /*
     * The whole reason this helper exists rather than a comparison in
     * place: as strings, "512M" sorts below "64M".
     */
    expect(BatchMemory::bytes('512M'))->toBeGreaterThan(BatchMemory::bytes('64M'))
        ->and(BatchMemory::bytes('256M'))->toBe(268435456)
        ->and(BatchMemory::bytes('1G'))->toBe(1073741824)
        ->and(BatchMemory::bytes('1024K'))->toBe(1048576)
        ->and(BatchMemory::bytes('1048576'))->toBe(1048576);
});

it('treats no limit as more than any limit', function (): void {
    expect(BatchMemory::bytes('-1'))->toBe(PHP_INT_MAX);
});

it('raises the ceiling to what is configured', function (): void {
    ini_set('memory_limit', '256M');
    config()->set('edge.batch_memory_limit', '512M');

    expect(BatchMemory::raise())->toBe('512M')
        ->and(ini_get('memory_limit'))->toBe('512M');
});

it('never lowers a ceiling somebody asked for', function (): void {
    /*
     * A developer running `php -d memory_limit=1G` has said what they
     * want. Taking it away would be the opposite of the point.
     */
    ini_set('memory_limit', '1G');
    config()->set('edge.batch_memory_limit', '512M');

    expect(BatchMemory::raise())->toBe('1G')
        ->and(ini_get('memory_limit'))->toBe('1G');
});

it('leaves an unlimited process unlimited', function (): void {
    ini_set('memory_limit', '-1');
    config()->set('edge.batch_memory_limit', '512M');

    expect(BatchMemory::raise())->toBe('-1');
});

it('has a configured default, so nothing depends on the platform value', function (): void {
    expect(config('edge.batch_memory_limit'))->not->toBeNull()
        ->and(BatchMemory::bytes((string) config('edge.batch_memory_limit')))
        ->toBeGreaterThan(BatchMemory::bytes('256M'));
});
