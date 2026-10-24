<?php

declare(strict_types=1);

use App\Services\Ingestion\MakesHttpRequests;

/*
 * A class method silently beats a trait's method of the same name. PHP
 * gives no warning and no error, so a source that happens to name a
 * private helper fetch() quietly replaces the HTTP plumbing it is sitting
 * on - and every request it makes becomes a type error the moment one is
 * attempted.
 *
 * CfbdFootballSource did exactly that and nobody found out for weeks,
 * because without a key the source refused to run before it could fail.
 * A collision that only shows up once a credential arrives is not
 * something to rely on noticing.
 */

/** @return array<int, class-string> */
function httpSources(): array
{
    $classes = [];

    foreach (glob(app_path('Services/Ingestion/*.php')) as $file) {
        $class = sprintf('App\\Services\\Ingestion\\%s', basename($file, '.php'));

        if (! class_exists($class)) {
            continue;
        }

        if (in_array(MakesHttpRequests::class, class_uses_recursive($class), true)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('finds the sources that use the shared HTTP plumbing', function () {
    // If this ever returns nothing the test below is vacuously green,
    // which is worse than it failing.
    expect(httpSources())->not->toBeEmpty();
});

it('never lets a source redeclare a method of the HTTP trait', function () {
    $traitMethods = array_map(
        fn (ReflectionMethod $m) => $m->getName(),
        (new ReflectionClass(MakesHttpRequests::class))->getMethods(),
    );

    $collisions = [];

    foreach (httpSources() as $class) {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods() as $method) {
            // A method a trait brought in reports the using class as its
            // declaring class, so that alone says nothing. What separates
            // the two is the file it is written in.
            if ($method->getFileName() !== $reflection->getFileName()) {
                continue;
            }

            if (in_array($method->getName(), $traitMethods, true)) {
                $collisions[] = $class.'::'.$method->getName().'()';
            }
        }
    }

    expect($collisions)->toBe([], implode(', ', $collisions).' shadows the trait it is using.');
});
