<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Proves the database itself refuses the two things that would quietly
 * corrupt the track record: editing history, and storing a prediction made
 * after the game started.
 *
 * Runs against Postgres in CI, where the triggers and check constraints
 * actually exist. On SQLite it reports that the model-level guards are what
 * is in force, since SQLite cannot add either after table creation.
 *
 * Two things about how it is written matter more than they look.
 *
 * The whole run happens inside a transaction that is always rolled back. A
 * check proving odds_snapshots cannot be deleted necessarily leaves behind
 * a row it cannot delete, so every run wrote permanent fixtures into the
 * table whose integrity is the product's evidence base - two quotes a run,
 * for ever, from a book that does not exist.
 *
 * And the setup happens outside the assertions. `expectRejected` reads a
 * failure as the guard firing, which is only sound while the only thing
 * that can fail is the thing being tested. It was not: the seeded model
 * version collided with a real one on the unique key, so the leakage check
 * threw during setup and the throw was reported as a pass. The most
 * important guard in the schema was green without once being exercised.
 * Setup that fails now stops the command and says so.
 */
class VerifyGuardsCommand extends Command
{
    protected $signature = 'edge:verify:guards';

    protected $description = 'Verify the append-only triggers and leakage constraints are in force';

    public function handle(): int
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            $this->warn('Not Postgres: database triggers are not present here.');
            $this->line('The AppendOnly trait and the FeatureSet guard cover these rules locally,');
            $this->line('and the Postgres job in CI proves the database-level version.');

            return self::SUCCESS;
        }

        DB::beginTransaction();

        try {
            $fixtures = $this->seed();
        } catch (Throwable $e) {
            DB::rollBack();

            $this->error('Could not set up the check: '.$e->getMessage());
            $this->line('Nothing was proved. That is a failure of this command, not of the guards.');

            return self::FAILURE;
        }

        try {
            $failures = $this->runChecks($fixtures);
        } finally {
            /*
             * Always, whatever happened. The point is to prove the guards
             * hold, not to leave evidence of having tested them in the
             * tables the guards exist to protect.
             */
            DB::rollBack();
        }

        $this->newLine();

        if ($failures > 0) {
            $this->error("{$failures} guard(s) did not hold. The database is not protecting history.");

            return self::FAILURE;
        }

        $this->info('All integrity guards held. Nothing was left behind.');

        return self::SUCCESS;
    }

    /**
     * Everything the checks need, created before any of them runs.
     *
     * @return array{event: int, model: int, snapshots: array<int, int>}
     */
    private function seed(): array
    {
        $event = $this->seedEvent();

        return [
            'event' => $event,
            'model' => $this->seedModelVersion(),
            // One each: the first check proves a row cannot be updated,
            // the second that it cannot be deleted.
            'snapshots' => [$this->seedSnapshot($event), $this->seedSnapshot($event)],
        ];
    }

    /** @param  array{event: int, model: int, snapshots: array<int, int>}  $fixtures */
    private function runChecks(array $fixtures): int
    {
        $failures = 0;

        $failures += $this->expectRejected(
            'append-only update on odds_snapshots',
            fn () => DB::table('odds_snapshots')
                ->where('id', $fixtures['snapshots'][0])
                ->update(['price_american' => -200]),
        );

        $failures += $this->expectRejected(
            'append-only delete on odds_snapshots',
            fn () => DB::table('odds_snapshots')
                ->where('id', $fixtures['snapshots'][1])
                ->delete(),
        );

        $failures += $this->expectRejected(
            'prediction timestamped after kickoff',
            fn () => DB::table('predictions')->insert([
                'event_id' => $fixtures['event'],
                'model_version_id' => $fixtures['model'],
                'market' => 'h2h',
                'side' => 'home',
                'prob' => 0.55,
                'fair_price_american' => -122,
                'predicted_at' => now()->addHours(2),
                'event_starts_at' => now()->addHour(),
                'created_at' => now(),
            ]),
        );

        return $failures;
    }

    /**
     * Runs one attempt the database is supposed to refuse.
     *
     * Only a query error counts. Anything else is this command being
     * wrong rather than the database being right, and telling those two
     * apart is the whole value of the check: a setup failure caught here
     * reads exactly like a guard holding.
     *
     * Each attempt gets its own savepoint, because a failed statement
     * poisons a Postgres transaction until one is rolled back to - so
     * without this the first rejection would make every later check fail
     * for a reason with nothing to do with what it is testing.
     */
    private function expectRejected(string $what, callable $attempt): int
    {
        try {
            DB::transaction($attempt);
        } catch (QueryException) {
            $this->line("  <fg=green>rejected</> {$what}");

            return 0;
        }

        $this->line("  <fg=red>ALLOWED</> {$what}");

        return 1;
    }

    private function seedEvent(): int
    {
        $home = $this->seedTeam('guard-home');
        $away = $this->seedTeam('guard-away');

        $existing = DB::table('events')->where('odds_api_event_id', 'guard-check')->value('id');

        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('events')->insertGetId([
            'sport' => 'nfl',
            'home_team_id' => $home,
            'away_team_id' => $away,
            'starts_at' => now()->addHour(),
            'status' => 'scheduled',
            'odds_api_event_id' => 'guard-check',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedTeam(string $slug): int
    {
        $existing = DB::table('teams')->where('slug', $slug)->value('id');

        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('teams')->insertGetId([
            'sport' => 'nfl',
            'name' => $slug,
            'display_name' => $slug,
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A model version of a type nothing real uses.
     *
     * model_versions is unique on sport, market, model_type and version,
     * and this asked for nfl/h2h/elo version 1 - which is a real trained
     * model in any database old enough to have one. The insert raised a
     * unique violation, the violation was read as the leakage constraint
     * doing its job, and the check passed without reaching it.
     */
    private function seedModelVersion(): int
    {
        return (int) DB::table('model_versions')->insertGetId([
            'sport' => 'nfl',
            'market' => 'h2h',
            'model_type' => 'guard-check',
            'version' => 1,
            'label' => 'guard-check',
            'params' => json_encode([]),
            'trained_at' => now(),
            'trained_through_at' => now(),
            'status' => 'candidate',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedSnapshot(int $event): int
    {
        $book = DB::table('books')->where('key', 'guard-check')->value('id')
            ?? DB::table('books')->insertGetId([
                'key' => 'guard-check',
                'title' => 'Guard check',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return (int) DB::table('odds_snapshots')->insertGetId([
            'event_id' => $event,
            'book_id' => $book,
            'market' => 'h2h',
            'side' => 'home',
            'price_american' => -110,
            'price_decimal' => 1.9091,
            'pulled_at' => now(),
            'created_at' => now(),
        ]);
    }
}
