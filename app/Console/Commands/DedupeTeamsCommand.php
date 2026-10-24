<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Sport;
use App\Models\Team;
use App\Services\Teams\NameNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds teams that are the same team twice, and merges the empty copy away.
 *
 * Two have happened here. Both came from the same mistake: treating a label
 * as an identity. The import keyed on the slug, so a team whose name was
 * normalised differently on a later run came back as a second row - and the
 * cross-sport id bug meant the id lookup that should have caught it was
 * answering for another league.
 *
 * Merging is only safe one way. Four of the tables that point at a team are
 * append-only, enforced by a Postgres trigger, so a row in them can be
 * neither repointed nor cleared by a cascade. Whichever copy those tables
 * reference is therefore the copy that has to survive; anything else would
 * mean rewriting immutable history. If more than one copy is referenced
 * that way, this refuses the group and says so, because that is a judgement
 * about real data and not a cleanup.
 */
class DedupeTeamsCommand extends Command
{
    protected $signature = 'edge:teams:dedupe
                            {--sport= : Limit to one sport}
                            {--apply : Merge them; without this it only reports}';

    protected $description = 'Merge duplicate team rows, keeping the one that holds the history';

    /**
     * Tables a trigger will not let us touch, so their team is the keeper.
     *
     * @var array<string, string>
     */
    private const IMMUTABLE = [
        'odds_snapshots' => 'selection_team_id',
        'injury_reports' => 'team_id',
        'probable_starters' => 'team_id',
        'feature_sets' => 'team_id',
    ];

    public function handle(): int
    {
        $sports = $this->option('sport')
            ? array_filter([Sport::tryFrom((string) $this->option('sport'))])
            : Sport::cases();

        if ($sports === []) {
            $this->error('Unknown sport.');

            return self::FAILURE;
        }

        $merged = 0;
        $refused = 0;
        $groups = 0;

        foreach ($sports as $sport) {
            foreach ($this->duplicateGroups($sport) as $name => $teams) {
                $groups++;

                $withHistory = $teams->filter(fn (Team $t) => $this->immutableCount($t->id) > 0);

                if ($withHistory->count() > 1) {
                    $refused++;
                    $this->newLine();
                    $this->warn(sprintf('%s (%s): %d copies hold immutable history, so this needs a person.', $name, $sport->value, $withHistory->count()));

                    foreach ($teams as $team) {
                        $this->line(sprintf('    id %-6d slug %-38s %d immutable, %d mutable', $team->id, $team->slug, $this->immutableCount($team->id), $this->mutableCount($team->id)));
                    }

                    continue;
                }

                // The copy the immutable tables point at, or failing that
                // the one carrying the most of everything else. The oldest
                // id breaks a tie, which is the row that has been around
                // long enough for something outside this app to know it.
                $keeper = $withHistory->first()
                    ?? $teams->sortBy([
                        fn (Team $a, Team $b) => $this->mutableCount($b->id) <=> $this->mutableCount($a->id),
                        fn (Team $a, Team $b) => $a->id <=> $b->id,
                    ])->first();

                $losers = $teams->reject(fn (Team $t) => $t->id === $keeper->id);

                $this->newLine();
                $this->line(sprintf('<fg=green>%s</> (%s): keeping id %d, slug %s', $name, $sport->value, $keeper->id, $keeper->slug));

                foreach ($losers as $loser) {
                    $moved = $this->mutableCount($loser->id);

                    $this->line(sprintf(
                        '  %s id %-6d slug %-38s %s',
                        $this->option('apply') ? 'merge' : 'would merge',
                        $loser->id,
                        $loser->slug,
                        $moved === 0 ? 'nothing to move' : $moved.' reference(s) to move',
                    ));

                    if ($this->option('apply')) {
                        $this->merge($keeper, $loser);
                        $merged++;
                    }
                }
            }
        }

        $this->newLine();

        if ($groups === 0) {
            $this->info('No duplicate teams.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d duplicate group(s), %d merged, %d left for a person.%s',
            $groups,
            $merged,
            $refused,
            $this->option('apply') ? '' : ' Run with --apply to merge them.',
        ));

        return self::SUCCESS;
    }

    /**
     * Teams in one sport whose names land on the same key.
     *
     * Grouped on the normalised display name rather than the slug, because
     * a changed normalizer is exactly how these rows appear and their slugs
     * are what differ.
     *
     * @return array<string, Collection<int, Team>>
     */
    private function duplicateGroups(Sport $sport): array
    {
        return Team::query()
            ->where('sport', $sport->value)
            ->get()
            ->groupBy(fn (Team $team) => NameNormalizer::normalize($team->display_name))
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->mapWithKeys(fn (Collection $group, string $key) => [$group->first()->display_name => $group])
            ->all();
    }

    /** References a trigger will not let us move or clear. */
    private function immutableCount(int $teamId): int
    {
        $total = 0;

        foreach (self::IMMUTABLE as $table => $column) {
            $total += DB::table($table)->where($column, $teamId)->count();
        }

        return $total;
    }

    /** References that can be repointed. */
    private function mutableCount(int $teamId): int
    {
        return DB::table('events')->where('home_team_id', $teamId)->count()
            + DB::table('events')->where('away_team_id', $teamId)->count()
            + DB::table('team_game_stats')->where('team_id', $teamId)->count()
            + DB::table('team_game_stats')->where('opponent_team_id', $teamId)->count();
    }

    private function merge(Team $keeper, Team $loser): void
    {
        DB::transaction(function () use ($keeper, $loser): void {
            DB::table('events')->where('home_team_id', $loser->id)->update(['home_team_id' => $keeper->id]);
            DB::table('events')->where('away_team_id', $loser->id)->update(['away_team_id' => $keeper->id]);

            // One row per team per game, so a stat line the keeper already
            // has cannot be moved on top of it. The keeper's is the one to
            // trust: it is the row the rest of its history agrees with.
            $held = DB::table('team_game_stats')->where('team_id', $keeper->id)->pluck('event_id');

            DB::table('team_game_stats')->where('team_id', $loser->id)->whereIn('event_id', $held)->delete();
            DB::table('team_game_stats')->where('team_id', $loser->id)->update(['team_id' => $keeper->id]);
            DB::table('team_game_stats')->where('opponent_team_id', $loser->id)->update(['opponent_team_id' => $keeper->id]);

            // External ids and aliases are keyed on more than the team, so
            // moving them can collide with one the keeper already holds.
            // Dropped rather than moved in that case: the keeper's row says
            // the same thing.
            $this->moveOrDrop('team_external_ids', ['source', 'external_id', 'sport'], $keeper, $loser);
            $this->moveOrDrop('team_aliases', ['sport', 'source', 'alias_norm'], $keeper, $loser);

            $loser->delete();
        });
    }

    /**
     * Repoint rows at the keeper, dropping any the keeper already has.
     *
     * @param  array<int, string>  $key
     */
    private function moveOrDrop(string $table, array $key, Team $keeper, Team $loser): void
    {
        foreach (DB::table($table)->where('team_id', $loser->id)->get() as $row) {
            $clash = DB::table($table)->where('team_id', $keeper->id);

            foreach ($key as $column) {
                $clash->where($column, $row->$column);
            }

            if ($clash->exists()) {
                DB::table($table)->where('id', $row->id)->delete();

                continue;
            }

            DB::table($table)->where('id', $row->id)->update(['team_id' => $keeper->id]);
        }
    }
}
