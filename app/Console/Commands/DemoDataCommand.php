<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SyncRun;
use App\Models\VlBoardEntry;
use App\Models\VlEvent;
use App\Models\VlModel;
use App\Models\VlOddsDisplay;
use App\Services\EdgeLab\Mirror;
use Illuminate\Console\Command;

/**
 * Fills the dashboard with invented data so the layout can be looked at.
 *
 * This exists because a design review needs a full screen and the honest
 * product currently has an empty one: no market is proven, so there are
 * no picks, and that is the correct state rather than a gap to be filled.
 *
 * It refuses to run in production. Not as a convenience - fabricated
 * picks and a fabricated track record are exactly what the product
 * promises never to show, and the only thing standing between a demo
 * seeder and a lie told to a paying customer is this check.
 */
class DemoDataCommand extends Command
{
    protected $signature = 'vigil:demo {--fresh : Clear the mirrors first}';

    protected $description = 'Seed invented board data for local design review. Never runs in production.';

    public function handle(Mirror $mirror): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production. This data is invented.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            // Append-only tables, so this is a truncate rather than a
            // delete through the model, which would quite rightly throw.
            foreach (['vl_board_entries', 'vl_events', 'vl_models', 'vl_odds_display', 'sync_runs'] as $table) {
                \DB::table($table)->truncate();
            }
        }

        $games = [
            ['nfl', 'BUF @ KC', 'Bills -3.5', 0.76, -110, 0.124],
            ['nba', 'LAL @ DEN', 'Over 224.5', 0.72, -105, 0.098],
            ['mlb', 'NYY @ BOS', 'Yankees ML', 0.69, 120, 0.081],
            ['nfl', 'DAL @ SF', '49ers -2.5', 0.67, -115, 0.069],
            ['nhl', 'EDM @ VGK', 'Over 6.0', 0.64, -110, 0.057],
        ];

        foreach ($games as $i => [$sport, $matchup, $selection, $prob, $price, $edge]) {
            $id = $i + 1;

            $mirror->write(VlBoardEntry::class, [
                'id' => $id, 'matchup' => $matchup, 'demo' => true,
            ], [
                'edge_lab_id' => $id,
                'edge_lab_event_id' => 100 + $id,
                'sport' => $sport,
                'market' => 'h2h',
                'side' => 'home',
                'selection' => $selection,
                'model_prob' => $prob,
                'fair_prob' => $prob - 0.03,
                'edge' => $edge + 0.01,
                'edge_at_price' => $edge,
                'best_book' => 'hardrockbet',
                'best_book_title' => 'Hard Rock Bet',
                'best_price_american' => $price,
                'market_verdict' => 'proven',
                'recommendation' => 'bet',
                'reason' => 'Line moved toward us since open; the price still clears the threshold.',
                'flagged_at' => now()->subHour(),
            ]);

            [$away, $home] = explode(' @ ', $matchup);

            $mirror->write(VlEvent::class, ['id' => 100 + $id, 'demo' => true], [
                'edge_lab_id' => 100 + $id,
                'sport' => $sport,
                'starts_at' => now()->addHours(3 + $i),
                'status' => 'scheduled',
                'home_name' => $home,
                'home_abbreviation' => $home,
                'away_name' => $away,
                'away_abbreviation' => $away,
            ]);

            $mirror->write(VlModel::class, ['id' => $id, 'demo' => true], [
                'edge_lab_id' => $id,
                'sport' => $sport,
                'market' => 'h2h',
                'verdict' => 'proven',
                'allows_picks' => true,
                'predictions' => 420,
                'trained_at' => now()->subWeek(),
            ]);

            foreach ([[$price - 5, 40], [$price, 5]] as [$p, $minutesAgo]) {
                VlOddsDisplay::create([
                    'edge_lab_event_id' => 100 + $id,
                    'book' => 'hardrockbet',
                    'book_title' => 'Hard Rock Bet',
                    'market' => 'h2h',
                    'side' => 'home',
                    'price_american' => $p,
                    'price_decimal' => 1.9,
                    'quoted_at' => now()->subMinutes($minutesAgo),
                    'digest' => str_pad((string) ($id * 100 + $minutesAgo), 64, '0'),
                    'observed_at' => now()->subMinutes($minutesAgo),
                ]);
            }
        }

        foreach (['board', 'events', 'models'] as $feed) {
            SyncRun::create([
                'feed' => $feed, 'status' => 'success', 'cursor_after' => 5,
                'records_fetched' => 5, 'records_written' => 5,
                'started_at' => now()->subMinutes(2), 'finished_at' => now()->subMinutes(2),
            ]);
        }

        $this->info('Seeded invented board data for design review.');
        $this->line('<fg=yellow>These picks are not real and no market is actually proven.</>');

        return self::SUCCESS;
    }
}
