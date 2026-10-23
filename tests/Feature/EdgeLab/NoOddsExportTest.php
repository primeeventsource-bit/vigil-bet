<?php

declare(strict_types=1);

use App\Services\EdgeLab\EdgeLabClient;
use Illuminate\Support\Facades\Route;

/*
 * The odds licence allows displaying prices inside a paid interface and
 * forbids reselling or repackaging the feed. The practical difference is
 * almost entirely whether anything can pull the whole board at once, so
 * these assertions are about the shape of the application rather than the
 * behaviour of one handler - a future route that quietly served a slate
 * would pass every other test in this suite and fail here.
 */

it('has no public api, feed or odds export route', function () {
    /*
     * The licence boundary is about data leaving in bulk, not about the
     * word "odds" appearing in a URL. A signed-in HTML page that shows
     * prices is the permitted case - displaying inside a paid interface
     * is exactly what is allowed - so pages are judged on two things
     * instead: they must be behind a login, and they must not be a
     * machine-readable endpoint.
     */
    $dataShaped = '/\bapi\b|feed|export|\.csv|\.json|download|bulk/i';

    $offenders = collect(Route::getRoutes())
        ->reject(fn ($route) => str_starts_with($route->uri(), 'livewire/'))
        ->reject(fn ($route) => str_starts_with($route->uri(), 'storage/'))
        ->filter(fn ($route) => (bool) preg_match($dataShaped, $route->uri()))
        ->map(fn ($route) => $route->uri());

    expect($offenders->values()->all())->toBe([]);
});

it('keeps every page that shows prices behind a login', function () {
    $pricePages = collect(Route::getRoutes())
        ->filter(fn ($route) => (bool) preg_match('/odds|prices/i', $route->uri()))
        ->reject(fn ($route) => str_starts_with($route->uri(), 'livewire/'));

    expect($pricePages)->not->toBeEmpty();

    foreach ($pricePages as $route) {
        expect($route->gatherMiddleware())->toContain('auth');
    }
});

it('asks Edge Lab for odds one event at a time', function () {
    // Edge Lab has no sport-wide or date-wide odds route, and the client
    // has no method that could call one. The only way to a price is an
    // event id.
    $client = new ReflectionClass(EdgeLabClient::class);
    $odds = $client->getMethod('odds');

    expect($odds->getNumberOfParameters())->toBe(1)
        ->and($odds->getParameters()[0]->getName())->toBe('edgeLabEventId')
        ->and((string) $odds->getParameters()[0]->getType())->toBe('int');
});

it('exposes no method that could fetch odds in bulk', function () {
    $methods = collect((new ReflectionClass(EdgeLabClient::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn (ReflectionMethod $m) => $m->getName())
        ->filter(fn (string $name) => (bool) preg_match('/odds|prices|quotes/i', $name));

    expect($methods->values()->all())->toBe(['odds']);
});
