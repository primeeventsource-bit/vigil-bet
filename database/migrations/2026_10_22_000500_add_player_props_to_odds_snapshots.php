<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Player props, in the same append-only table as everything else.
 *
 * One table rather than two, because a prop quote is the same fact as a
 * moneyline quote - a book, a side, a price, a moment - and splitting them
 * would mean two closing-line markers, two line shoppers and two places to
 * remember that a quote twenty minutes old is not a price on offer.
 *
 * What a prop is not is a *market* in the sense the rest of this app uses
 * the word. `Market` exists so the three core markets can be matched
 * exhaustively: it answers sides(), hasLine(), which pricer to run and how
 * to label a selection, and a prop has none of those answers. So props
 * carry a raw `market_key` and leave `market` null, and the three core
 * markets carry `market` and leave `market_key` null. Exactly one of the
 * two is always set, which Postgres enforces below.
 *
 * The practical effect is the one that matters: every reader that feeds the
 * model asks for a core market by name, so a prop row can never reach a
 * prediction, a verdict or a pick. Props are display and line shopping
 * only until a props model passes the gate, and the schema is what makes
 * that true rather than a convention somebody has to remember.
 *
 * Nothing is backfilled here. `odds_snapshots` is append-only and Postgres
 * enforces it with a trigger, so an UPDATE over history would be rejected -
 * correctly. Hence null on the old rows and a reader that knows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odds_snapshots', function (Blueprint $table) {
            // The Odds API market key, verbatim, for anything outside the
            // three core markets. 48 characters covers the longest props
            // key with room to spare.
            $table->string('market_key', 48)->nullable()->after('market');
            // Who the prop is about. The feed calls this `description`.
            $table->string('player_name', 120)->nullable()->after('selection_team_id');
            // Where the row came from: a live pull, or the historical
            // endpoint. Null on rows written before this column existed,
            // all of which were live pulls or the nflverse line import.
            $table->string('provenance', 16)->nullable()->after('pulled_at');

            // A prop has no core market, so this is the column that has to
            // give. Readers treat a null market as "look at market_key".
            $table->string('market', 16)->nullable()->change();

            $table->index(['event_id', 'market_key', 'pulled_at'], 'odds_snapshots_prop_idx');
        });

        if (! $this->onPostgres()) {
            return;
        }

        // A core market and a prop are different things, and a row that
        // claims to be both, or claims to be neither, is a bug rather than
        // a datum. NOT VALID because the rows already in the table predate
        // both columns and cannot be re-read to prove themselves: the
        // constraint is checked on everything written from here on, which
        // is the part that can still go wrong.
        DB::statement('ALTER TABLE odds_snapshots ADD CONSTRAINT odds_snapshots_one_market_kind
                       CHECK ((market IS NULL) <> (market_key IS NULL))
                       NOT VALID');
    }

    /**
     * Reversing this needs the prop rows gone first, and will refuse loudly
     * rather than quietly if they are not: restoring NOT NULL on a column
     * full of nulls fails, which is the correct outcome. The alternative is
     * deleting them here, and this table is append-only - the Postgres
     * trigger would reject that, also correctly.
     */
    public function down(): void
    {
        if ($this->onPostgres()) {
            DB::statement('ALTER TABLE odds_snapshots DROP CONSTRAINT IF EXISTS odds_snapshots_one_market_kind');
        }

        Schema::table('odds_snapshots', function (Blueprint $table) {
            $table->dropIndex('odds_snapshots_prop_idx');
            $table->dropColumn(['market_key', 'player_name', 'provenance']);
            $table->string('market', 16)->nullable(false)->change();
        });
    }

    private function onPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
