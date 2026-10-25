<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * Postgres only, and skipped loudly rather than passed quietly.
 *
 * The triggers and check constraints these assert only exist on Postgres;
 * SQLite cannot add either after table creation. A skipped test says so in
 * the runner's output, which is the whole difference between this and the
 * bug it was written for - a check that reported green while failing during
 * setup, and so proved nothing while looking exactly like proof.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Postgres only: the triggers and CHECK constraints do not exist on SQLite.');
    }
});

it('proves all three guards actually fire', function () {
    $this->artisan('edge:verify:guards')
        ->expectsOutputToContain('rejected')
        ->expectsOutputToContain('All integrity guards held')
        ->assertExitCode(0);
});

/*
 * The check cannot be allowed to cost what it proves.
 *
 * Proving that odds_snapshots refuses a delete means writing a row that
 * cannot then be deleted, so every run used to leave two fake quotes in
 * the append-only table that is the product's evidence base, from a book
 * that does not exist, permanently.
 */
it('leaves nothing behind in the tables it writes to', function () {
    $before = [
        'odds_snapshots' => DB::table('odds_snapshots')->count(),
        'events' => DB::table('events')->count(),
        'teams' => DB::table('teams')->count(),
        'books' => DB::table('books')->count(),
        'model_versions' => DB::table('model_versions')->count(),
        'predictions' => DB::table('predictions')->count(),
    ];

    $this->artisan('edge:verify:guards')->assertExitCode(0);

    foreach ($before as $table => $count) {
        expect(DB::table($table)->count())->toBe($count, "{$table} grew");
    }
});

/*
 * The failure that made this worth rewriting.
 *
 * The fixture model version asked for nfl/h2h/elo version 1, which is a
 * real trained model in any database old enough to have one, and
 * model_versions is unique on exactly that. So the insert raised a unique
 * violation, the violation was caught as the leakage constraint doing its
 * job, and the check passed without ever reaching it.
 *
 * A setup failure has to be a failure. It cannot read as the thing being
 * tested, because then the command reports most confidently in precisely
 * the case where it has measured nothing.
 */
it('fails loudly when its own setup collides with real data', function () {
    DB::table('model_versions')->insert([
        'sport' => 'nfl',
        'market' => 'h2h',
        'model_type' => 'guard-check',
        'version' => 1,
        'label' => 'squatter',
        'params' => json_encode([]),
        'trained_at' => now(),
        'trained_through_at' => now(),
        'status' => 'candidate',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('edge:verify:guards')
        ->expectsOutputToContain('Could not set up the check')
        ->assertExitCode(1);
});
