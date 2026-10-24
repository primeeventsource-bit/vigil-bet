<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
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

        $failures = 0;

        $failures += $this->expectRejected(
            'append-only update on odds_snapshots',
            fn () => DB::table('odds_snapshots')->where('id', $this->seedSnapshot())->update(['price_american' => -200]),
        );

        $failures += $this->expectRejected(
            'append-only delete on odds_snapshots',
            fn () => DB::table('odds_snapshots')->where('id', $this->seedSnapshot())->delete(),
        );

        $failures += $this->expectRejected(
            'prediction timestamped after kickoff',
            fn () => DB::table('predictions')->insert([
                'event_id' => $this->seedEvent(),
                'model_version_id' => $this->seedModelVersion(),
                'market' => 'h2h',
                'side' => 'home',
                'prob' => 0.55,
                'fair_price_american' => -122,
                'predicted_at' => now()->addHours(2),
                'event_starts_at' => now()->addHour(),
                'created_at' => now(),
            ]),
        );

        $this->newLine();

        if ($failures > 0) {
            $this->error("{$failures} guard(s) did not hold. The database is not protecting history.");

            return self::FAILURE;
        }

        $this->info('All integrity guards held.');

        return self::SUCCESS;
    }

    private function expectRejected(string $what, callable $attempt): int
    {
        try {
            $attempt();
        } catch (Throwable) {
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

    private function seedModelVersion(): int
    {
        $existing = DB::table('model_versions')->where('label', 'guard-check')->value('id');

        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('model_versions')->insertGetId([
            'sport' => 'nfl',
            'market' => 'h2h',
            'model_type' => 'elo',
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

    private function seedSnapshot(): int
    {
        $book = DB::table('books')->where('key', 'guard-check')->value('id')
            ?? DB::table('books')->insertGetId([
                'key' => 'guard-check',
                'title' => 'Guard check',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return (int) DB::table('odds_snapshots')->insertGetId([
            'event_id' => $this->seedEvent(),
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
