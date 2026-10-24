<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database-level enforcement of the two rules the whole track record rests on:
 *
 *  1. Append-only history. A snapshot, prediction or bet event is never
 *     updated or deleted once written.
 *  2. A prediction is timestamped strictly before kickoff.
 *
 * These are Postgres constructs. On SQLite (local dev) the same rules are
 * enforced by the AppendOnly model trait and covered by tests, because SQLite
 * cannot add constraints or row-level triggers after table creation.
 */
return new class extends Migration
{
    /** Tables whose rows are immutable once inserted. */
    private const APPEND_ONLY = [
        'odds_snapshots',
        'consensus_prices',
        'injury_reports',
        'probable_starters',
        'weather_readings',
        'feature_sets',
        'predictions',
        'value_bets',
        'arbitrage_opportunities',
        'middle_opportunities',
        'bankroll_settings',
        'bet_events',
        'bankroll_ledger',
    ];

    public function up(): void
    {
        if (! $this->onPostgres()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION edge_deny_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'Table % is append-only; % is not permitted', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        foreach (self::APPEND_ONLY as $table) {
            DB::unprepared(sprintf(
                'CREATE TRIGGER %1$s_append_only
                 BEFORE UPDATE OR DELETE ON %1$s
                 FOR EACH ROW EXECUTE FUNCTION edge_deny_mutation();',
                $table
            ));
        }

        DB::statement('ALTER TABLE predictions ADD CONSTRAINT predictions_before_kickoff
                       CHECK (predicted_at < event_starts_at)');

        DB::statement('ALTER TABLE predictions ADD CONSTRAINT predictions_prob_range
                       CHECK (prob > 0 AND prob < 1)');

        DB::statement('ALTER TABLE feature_sets ADD CONSTRAINT feature_sets_no_leakage
                       CHECK (max_source_at <= event_starts_at)');

        DB::statement('ALTER TABLE odds_snapshots ADD CONSTRAINT odds_snapshots_american_valid
                       CHECK (price_american <= -100 OR price_american >= 100)');

        DB::statement('ALTER TABLE bets ADD CONSTRAINT bets_american_valid
                       CHECK (price_american <= -100 OR price_american >= 100)');

        DB::statement('ALTER TABLE bets ADD CONSTRAINT bets_stake_non_negative
                       CHECK (stake_cents >= 0)');
    }

    public function down(): void
    {
        if (! $this->onPostgres()) {
            return;
        }

        foreach (self::APPEND_ONLY as $table) {
            DB::unprepared(sprintf('DROP TRIGGER IF EXISTS %1$s_append_only ON %1$s;', $table));
        }

        DB::unprepared('DROP FUNCTION IF EXISTS edge_deny_mutation();');

        DB::statement('ALTER TABLE predictions DROP CONSTRAINT IF EXISTS predictions_before_kickoff');
        DB::statement('ALTER TABLE predictions DROP CONSTRAINT IF EXISTS predictions_prob_range');
        DB::statement('ALTER TABLE feature_sets DROP CONSTRAINT IF EXISTS feature_sets_no_leakage');
        DB::statement('ALTER TABLE odds_snapshots DROP CONSTRAINT IF EXISTS odds_snapshots_american_valid');
        DB::statement('ALTER TABLE bets DROP CONSTRAINT IF EXISTS bets_american_valid');
        DB::statement('ALTER TABLE bets DROP CONSTRAINT IF EXISTS bets_stake_non_negative');
    }

    private function onPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
