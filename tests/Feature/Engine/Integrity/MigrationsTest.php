<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/*
 * Migrations have to run forwards and backwards over the whole chain.
 *
 * CI proves this on Postgres, which is where it matters most - the
 * append-only triggers and check constraints only exist there. But CI
 * proves it after the fact, and a broken `down()` is cheap to write and
 * invisible until someone rolls back. This runs the real chain against a
 * throwaway SQLite file, in a separate process so it cannot disturb the
 * in-memory database the rest of the suite shares.
 *
 * Deliberately not a hand-written copy of the migrations. A test that
 * restates what it is checking drifts away from the thing it checks and
 * then verifies nothing.
 */

function migrateFresh(string $database): ProcessResult
{
    return Process::path(base_path())
        ->timeout(180)
        ->env([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'DB_FOREIGN_KEYS' => 'true',
        ])
        ->run('php artisan migrate --force --no-interaction');
}

function rollbackAll(string $database): ProcessResult
{
    return Process::path(base_path())
        ->timeout(180)
        ->env([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'DB_FOREIGN_KEYS' => 'true',
        ])
        ->run('php artisan migrate:rollback --force --no-interaction');
}

function scratchDatabase(): string
{
    $path = sys_get_temp_dir().'/edge-migrations-'.bin2hex(random_bytes(6)).'.sqlite';

    file_put_contents($path, '');

    return $path;
}

it('runs the whole chain forwards against an empty database', function () {
    $database = scratchDatabase();

    try {
        $result = migrateFresh($database);

        expect($result->successful())->toBeTrue()
            ->and($result->output())->not->toContain('Nothing to migrate');
    } finally {
        @unlink($database);
    }
});

it('rolls the whole chain back again', function () {
    // One fresh database means every migration lands in one batch, so a
    // single rollback reverses all of them - which is exactly what CI
    // does, and the only way a down() on an early migration gets exercised.
    $database = scratchDatabase();

    try {
        expect(migrateFresh($database)->successful())->toBeTrue();

        $rollback = rollbackAll($database);

        expect($rollback->successful())->toBeTrue()
            ->and($rollback->errorOutput())->not->toContain('SQLSTATE');
    } finally {
        @unlink($database);
    }
});

it('leaves nothing of its own behind after rolling back', function () {
    $database = scratchDatabase();

    try {
        migrateFresh($database);
        rollbackAll($database);

        // Read straight off the file rather than through another artisan
        // call. The first version shelled out to tinker with a closure in
        // the argument, and `$t` inside a double-quoted shell word is a
        // variable to every shell but Windows': on Linux it expanded to
        // nothing, the probe returned "Array", and CI caught what passed
        // here. No shell, no quoting, no difference between platforms.
        $pdo = new PDO('sqlite:'.$database);

        $tables = $pdo
            ->query("select name from sqlite_master where type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN);

        // Proved to have actually looked before anything is concluded from
        // not finding something. An empty read would pass every assertion
        // below for the wrong reason.
        expect($tables)->toContain('migrations');

        // The migrations ledger survives a rollback by design; the tables
        // the migrations created must not.
        foreach (['events', 'odds_snapshots', 'bets', 'weather_readings', 'injury_reports'] as $table) {
            expect($tables)->not->toContain($table);
        }
    } finally {
        @unlink($database);
    }
});
