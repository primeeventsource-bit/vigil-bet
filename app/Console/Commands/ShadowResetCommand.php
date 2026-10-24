<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ShadowResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Empty the shadow record, once, before it has measured anything.
 *
 * The recorder deduplicated on `value_bet_id` for its first six hours.
 * The board is rescanned hourly, so the same selection was written down
 * once an hour: 92 rows standing for 31 bets. The readers were taught to
 * take the earliest row per selection, which makes the numbers right,
 * and leaves a table whose row count means nothing and which every
 * future reader has to know to be careful with.
 *
 * So this exists to start it clean. It is deliberately a command rather
 * than a statement someone types at a production database:
 *
 *  - It can only ever touch these two tables. A truncate typed by hand
 *    can touch anything, and the only thing standing between the two is
 *    whoever is typing.
 *  - It refuses once anything has been settled. The moment a result row
 *    exists the table holds a measurement that cannot be reproduced -
 *    a shadow pick is only writable before kickoff, so a graded one is
 *    gone for good - and no convenience is worth that.
 *  - It says what it did.
 *
 * The append-only triggers are not dropped and do not need to be: a
 * row-level BEFORE DELETE trigger does not fire on TRUNCATE in Postgres.
 * Worth knowing about those guards generally, and the reason this can
 * run without leaving the table unprotected halfway through.
 */
class ShadowResetCommand extends Command
{
    protected $signature = 'vigil:shadow-reset {--force : Required, because this cannot be undone}';

    protected $description = 'Empty the shadow record, refused once anything has been settled';

    public function handle(): int
    {
        $picks = DB::table('shadow_picks')->count();
        $results = ShadowResult::count();

        $this->line("shadow_picks: {$picks}");
        $this->line("shadow_results: {$results}");

        if ($results > 0) {
            $this->error(
                "Refused: {$results} settled results would be destroyed, and a shadow pick "
                .'cannot be rewritten after kickoff, so they could never be reproduced.'
            );

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn('Refused: pass --force. This cannot be undone.');

            return self::FAILURE;
        }

        if ($picks === 0) {
            $this->info('Already empty.');

            return self::SUCCESS;
        }

        /*
         * Both tables together, results first: shadow_results holds a
         * foreign key to shadow_picks, so either order but one table is
         * refused by the database.
         *
         * Truncate on Postgres, delete everywhere else. Not only because
         * `RESTART IDENTITY` is Postgres syntax: truncate is also what
         * lets this run without disarming anything, since a row-level
         * BEFORE DELETE trigger does not fire on it. SQLite never got
         * those triggers - the original migration made them pgsql-only -
         * so a plain delete there is equivalent and not a loophole.
         */
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('TRUNCATE shadow_results, shadow_picks RESTART IDENTITY');
        } else {
            DB::table('shadow_results')->delete();
            DB::table('shadow_picks')->delete();
        }

        $this->info("Emptied the shadow record: {$picks} rows removed, nothing settled was lost.");
        $this->line('published_picks: '.DB::table('published_picks')->count().' (untouched)');

        return self::SUCCESS;
    }
}
