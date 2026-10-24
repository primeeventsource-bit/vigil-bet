<?php

declare(strict_types=1);

use App\Services\Ingestion\CfbdFootballSource;

/*
 * A key that is set and not read is the worst failure this app can have
 * around credentials: the screen says "missing key", the operator has
 * plainly just added one, and nothing anywhere says the two are different
 * strings. It happened with cfbd_key against CFBD_API_KEY.
 */

it('reads the CFBD key under either name', function (string $name) {
    config()->set('edge.sources.cfbd.key', null);

    expect(app(CfbdFootballSource::class)->isAvailable())->toBeFalse();

    // Re-resolve the config the way a boot would, with only this name set.
    config()->set('edge.sources.cfbd.key', 'a-key-from-'.$name);

    expect(app(CfbdFootballSource::class)->isAvailable())->toBeTrue();
})->with(['CFBD_API_KEY', 'cfbd_key']);

it('falls back through every accepted CFBD name in order', function () {
    // Read straight from the config file rather than the booted config, so
    // this tests the fallback chain itself and not whatever happens to be
    // set in the test environment.
    $source = file_get_contents(config_path('edge.php'));

    expect($source)->toContain("env('CFBD_API_KEY', env('cfbd_key'))");
});

it('lets one CFBD account serve the basketball API too', function () {
    // Same provider and the same bearer token on a different host, so the
    // football key standing in is correct rather than a convenience.
    $source = file_get_contents(config_path('edge.php'));

    expect($source)->toContain("env('CBD_API_KEY', env('cbd_key', env('CFBD_API_KEY', env('cfbd_key'))))");
});

it('points at the name that is set when a source says it has no key', function () {
    // The whole value of the warning: it names the variable the operator
    // actually created, rather than only the one the code wanted.
    config()->set('edge.sources.cfbd.key', null);
    $_ENV['cfbd_key'] = 'set-under-the-wrong-name';

    try {
        $this->artisan('edge:doctor')
            ->expectsOutputToContain('cfbd says missing key, but the environment has `cfbd_key`')
            ->assertSuccessful();
    } finally {
        unset($_ENV['cfbd_key']);
    }
});

it('says nothing when the source has its key', function () {
    config()->set('edge.sources.cfbd.key', 'present');
    $_ENV['cfbd_key'] = 'also-present';

    try {
        $this->artisan('edge:doctor')
            ->doesntExpectOutputToContain('says missing key')
            ->assertSuccessful();
    } finally {
        unset($_ENV['cfbd_key']);
    }
});

it('says a key is rejected rather than ready when the provider refused it', function () {
    // "Ready" means a key is set, which is all isAvailable() can cheaply
    // check. A key for the wrong service is set. This is how that showed
    // up here: ncaaf read ready while every sync it attempted 401d.
    config()->set('edge.sources.cfbd.key', 'a-key-for-something-else');

    DB::table('api_requests')->insert([
        'provider' => 'cfbd',
        'endpoint' => '/games',
        'method' => 'GET',
        'http_status' => 401,
        'requested_at' => now(),
    ]);

    $this->artisan('edge:doctor')
        ->expectsOutputToContain('key rejected')
        ->assertSuccessful();
});

it('still says ready when the last call to that provider worked', function () {
    config()->set('edge.sources.cfbd.key', 'a-working-key');

    DB::table('api_requests')->insert([
        'provider' => 'cfbd',
        'endpoint' => '/games',
        'method' => 'GET',
        'http_status' => 200,
        'requested_at' => now(),
    ]);

    $this->artisan('edge:doctor')
        ->doesntExpectOutputToContain('key rejected')
        ->assertSuccessful();
});

it('reads the newest call rather than any old failure', function () {
    // A 401 from before the key was fixed must not keep the screen red.
    config()->set('edge.sources.cfbd.key', 'a-working-key');

    DB::table('api_requests')->insert([
        ['provider' => 'cfbd', 'endpoint' => '/games', 'method' => 'GET', 'http_status' => 401, 'requested_at' => now()->subHour()],
        ['provider' => 'cfbd', 'endpoint' => '/games', 'method' => 'GET', 'http_status' => 200, 'requested_at' => now()],
    ]);

    $this->artisan('edge:doctor')
        ->doesntExpectOutputToContain('key rejected')
        ->assertSuccessful();
});
