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
    $offenders = collect(Route::getRoutes())
        ->map(fn ($route) => $route->uri())
        ->reject(fn (string $uri) => str_starts_with($uri, 'livewire/') || str_starts_with($uri, 'storage/'))
        ->filter(fn (string $uri) => (bool) preg_match('/\bapi\b|odds|feed|export|\.csv|\.json|download|bulk/i', $uri));

    expect($offenders->values()->all())->toBe([]);
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
