<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Players\PlayerRatingCalculator;
use App\Services\Players\RatingSchema;
use App\Support\BatchMemory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recompute player ratings from the box scores on file.
 *
 * A rating is a function of the games played, so it is recomputed rather
 * than accumulated: the row for a player is replaced and `as_of` says
 * when. Nothing is appended, because yesterday's rating is not evidence
 * of anything once today's game is in.
 */
class PlayerRatingsCommand extends Command
{
    protected $signature = 'vigil:player-ratings
                            {sport=all : Sport key, or "all" for every supported league}';

    protected $description = 'Rate every player from their box scores, 0-99 within position';

    public function handle(PlayerRatingCalculator $calculator): int
    {
        // Four leagues of box scores in one pass; this is the batch work
        // the raised ceiling exists for.
        BatchMemory::raise();

        $sports = $this->sports();

        if ($sports === []) {
            $this->error('Unknown sport. Known: '.implode(', ', RatingSchema::sports()).', all');

            return self::FAILURE;
        }

        foreach ($sports as $sport) {
            $ratings = $calculator->forSport($sport);

            if ($ratings === []) {
                $this->line("  {$sport}: nothing rated - no player has ".PlayerRatingCalculator::MINIMUM_GAMES.' games on file');

                continue;
            }

            $now = now();

            $rows = array_map(static fn (array $rating): array => [
                ...$rating,
                'components' => json_encode($rating['components']),
                'as_of' => $now,
                'created_at' => $now,
            ], $ratings);

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('player_ratings')->upsert(
                    $chunk,
                    ['sport', 'player_external_id'],
                    ['player_name', 'peer_group', 'team_id', 'overall', 'components',
                        'games', 'measured_from', 'measured_to', 'as_of'],
                );
            }

            $this->line(sprintf(
                '  %s: rated %s players across %s',
                $sport,
                number_format(count($rows)),
                implode(', ', RatingSchema::groups($sport)),
            ));
        }

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function sports(): array
    {
        $argument = (string) $this->argument('sport');

        if ($argument === 'all') {
            /*
             * Only the leagues this product runs. College is off, and
             * rating players for a sport nobody can bet on would spend an
             * hour of CPU to fill a table nothing reads.
             */
            return array_values(array_intersect(
                RatingSchema::sports(),
                (array) config('edge.sports_enabled'),
            ));
        }

        return in_array($argument, RatingSchema::sports(), true) ? [$argument] : [];
    }
}
