<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 of the live engine: the feed audit.
 *
 * One row per source per poll, recording what the feed said and when we
 * received it. No model reads this table. Its only job is to answer, with
 * numbers, whether a live scanner is worth building on the feeds available:
 * how far behind each source runs, how long a book takes to reprice after a
 * score, and how often a market is simply gone.
 *
 * Append-only like every other history table here, for the same reason: a
 * latency measurement that can be revised after the fact measures nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_feed_probes', function (Blueprint $table) {
            $table->bigIncrements('id');

            // One probing run, so a session can be analysed on its own.
            $table->string('session', 32);
            $table->string('sport', 16);
            $table->string('source', 32);
            // game_state or odds.
            $table->string('kind', 16);

            // The game's id at this source, and a key that identifies the
            // same game across sources, which raw ids cannot do.
            $table->string('external_ref', 64)->nullable();
            $table->string('game_key', 128)->nullable();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();

            // What the reading said. Null means the source published none.
            $table->string('status', 24)->nullable();
            $table->integer('home_score')->nullable();
            $table->integer('away_score')->nullable();
            $table->smallInteger('period')->nullable();
            $table->integer('clock_seconds')->nullable();
            $table->string('possession', 8)->nullable();

            // Odds readings.
            $table->string('book_key', 48)->nullable();
            $table->string('market', 16)->nullable();
            $table->string('side', 8)->nullable();
            $table->integer('price_american')->nullable();
            $table->decimal('line', 6, 2)->nullable();
            $table->string('market_status', 16)->nullable();

            // When we received it, and when the source says it was true.
            $table->timestampTz('observed_at');
            $table->timestampTz('source_reported_at')->nullable();
            // observed_at - source_reported_at, where the source stamps its
            // readings at all. Null is a finding, not a gap.
            $table->integer('staleness_ms')->nullable();
            $table->integer('http_ms')->nullable();

            $table->boolean('ok')->default(true);
            $table->text('error')->nullable();

            // Did anything that moves a probability change since this
            // source's previous reading of this game.
            $table->boolean('changed')->default(false);
            $table->string('digest', 32)->nullable();

            $table->jsonb('payload')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['session', 'source', 'observed_at'], 'live_probes_session_idx');
            $table->index(['session', 'game_key', 'observed_at'], 'live_probes_game_idx');
            $table->index(['sport', 'kind', 'observed_at'], 'live_probes_sport_idx');
        });

        if ($this->onPostgres()) {
            DB::unprepared(
                'CREATE TRIGGER live_feed_probes_append_only
                 BEFORE UPDATE OR DELETE ON live_feed_probes
                 FOR EACH ROW EXECUTE FUNCTION edge_deny_mutation();'
            );
        }
    }

    public function down(): void
    {
        if ($this->onPostgres()) {
            DB::unprepared('DROP TRIGGER IF EXISTS live_feed_probes_append_only ON live_feed_probes;');
        }

        Schema::dropIfExists('live_feed_probes');
    }

    private function onPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
