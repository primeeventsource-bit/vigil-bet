<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Every scheduled job needs its own name
|--------------------------------------------------------------------------
|
| A scheduled closure keys its overlap lock on the name it was given, so
| two jobs sharing one share a mutex: whichever runs first silently
| suppresses the other for as long as it holds the lock.
|
| Two entries here were both called `settle-bets` - the customer bet
| grader at :05 and the engine's own at :20. That pair happened not to
| collide, because one is a command and a command keys its lock on the
| command string instead. It would have collided the moment either was
| rewritten as the other kind, and in the meantime `schedule:list` showed
| two different jobs under one name.
|
*/

use Illuminate\Console\Scheduling\Schedule;

/** @return array<int, string> */
function scheduleNames(): array
{
    $names = [];

    foreach (app(Schedule::class)->events() as $event) {
        if ($event->description !== null && $event->description !== '') {
            $names[] = $event->description;
        }
    }

    return $names;
}

it('reads a schedule with jobs in it', function (): void {
    // Without this, a schedule that failed to load would satisfy the
    // assertion below by finding no duplicates among no names.
    expect(count(scheduleNames()))->toBeGreaterThan(20);
});

it('gives every scheduled job a name of its own', function (): void {
    $names = scheduleNames();
    $duplicates = array_keys(array_filter(array_count_values($names), static fn (int $n): bool => $n > 1));

    expect($duplicates)->toBe([], 'Shared schedule names: '.implode(', ', $duplicates));
});
