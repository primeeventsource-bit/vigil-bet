<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\Sport;
use App\Models\DepthChartEntry;
use App\Services\Teams\TeamResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Depth charts from ESPN's keyless core endpoints.
 *
 * Two requests per team per run and nothing else, because the chart
 * returns athlete ids rather than names and `player_game_stats` already
 * stores ESPN's athlete id. Resolving names over the wire would have
 * meant roughly fifteen extra requests a team for information already in
 * the database.
 *
 * The ranks are the provider's own and are trustworthy where it has been
 * checked: Mahomes, Allen, Prescott and Purdy are all first choice on
 * their charts. It also demotes a player it believes is unavailable -
 * Jalen Hurts sat fourth on Philadelphia's chart two weeks after his
 * last game - which is a feature here rather than a fault, since the
 * question being asked is who the team is expected to play.
 */
class EspnDepthChartSource
{
    use MakesHttpRequests;

    public function __construct(private readonly TeamResolver $teams) {}

    private const SITE = 'https://site.api.espn.com/apis/site/v2/sports';

    private const CORE = 'https://sports.core.api.espn.com/v2/sports';

    /** Positions worth storing. The rest is noise for this purpose. */
    private const POSITIONS = ['qb', 'rb', 'wr', 'te'];

    public function key(): string
    {
        return 'espn';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Every team in the league, with the provider's own id.
     *
     * One request for the lot. The id is what the chart endpoint is keyed
     * on, and it is not stored anywhere on our side, so it is read fresh
     * rather than mirrored into a column that would then need keeping up.
     *
     * @return array<int, array{id: string, name: string, abbreviation: ?string}>
     */
    public function teams(Sport $sport): array
    {
        $path = $sport->config('espn_path');

        if ($path === null) {
            return [];
        }

        $payload = $this->getJson(self::SITE.'/'.$path.'/teams');
        $groups = $payload['sports'][0]['leagues'][0]['teams'] ?? [];

        $teams = [];

        foreach ($groups as $group) {
            $team = $group['team'] ?? [];

            if (! isset($team['id'])) {
                continue;
            }

            $teams[] = [
                'id' => (string) $team['id'],
                'name' => (string) ($team['displayName'] ?? $team['name'] ?? ''),
                'abbreviation' => isset($team['abbreviation']) ? (string) $team['abbreviation'] : null,
            ];
        }

        return $teams;
    }

    /**
     * One team's chart, flattened to the positions worth keeping.
     *
     * The provider returns several formations and the same player can
     * appear in more than one, so the formation is carried through and
     * the caller keeps the best rank rather than guessing which chart is
     * the real one.
     *
     * @return array<int, array{position: string, formation: ?string, rank: int, player_external_id: string}>
     */
    public function chartFor(Sport $sport, string $espnTeamId, int $season): array
    {
        $league = $this->leagueFor($sport);

        if ($league === null) {
            return [];
        }

        $url = self::CORE."/{$league}/seasons/{$season}/teams/{$espnTeamId}/depthcharts";

        $payload = $this->getJson($url);
        $rows = [];

        foreach (($payload['items'] ?? []) as $item) {
            $formation = isset($item['name']) ? (string) $item['name'] : null;

            foreach (($item['positions'] ?? []) as $slug => $node) {
                if (! in_array(mb_strtolower((string) $slug), self::POSITIONS, true)) {
                    continue;
                }

                foreach (($node['athletes'] ?? []) as $athlete) {
                    $id = $this->athleteIdFrom($athlete['athlete']['$ref'] ?? null);

                    if ($id === null || ! isset($athlete['rank'])) {
                        continue;
                    }

                    $rows[] = [
                        'position' => mb_strtoupper((string) $slug),
                        'formation' => $formation,
                        'rank' => (int) $athlete['rank'],
                        'player_external_id' => $id,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Reads a league's charts into the table.
     *
     * A team whose name does not resolve is logged and skipped, which is
     * the same bargain the injury source makes: a chart nobody can match
     * to a team is worth less than the run it would abort.
     *
     * @return array{teams: int, entries: int, unresolved: int}
     */
    public function sync(Sport $sport, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $season = (int) $asOf->year;

        $teams = 0;
        $entries = 0;
        $unresolved = 0;

        foreach ($this->teams($sport) as $remote) {
            $team = $this->teams->resolve(
                $sport,
                $remote['name'],
                $this->key(),
                $remote['id'],
                ['endpoint' => 'depthcharts'],
            );

            if ($team === null) {
                $unresolved++;

                Log::info('Depth chart team not resolved', [
                    'sport' => $sport->value,
                    'name' => $remote['name'],
                ]);

                continue;
            }

            $rows = $this->chartFor($sport, $remote['id'], $season);

            if ($rows === []) {
                continue;
            }

            $teams++;

            foreach ($rows as $row) {
                DepthChartEntry::create([
                    'sport' => $sport->value,
                    'team_id' => $team->id,
                    'player_external_id' => $row['player_external_id'],
                    'player_name' => null,
                    'position' => $row['position'],
                    'formation' => $row['formation'],
                    'rank' => $row['rank'],
                    'source' => $this->key(),
                    'as_of' => $asOf,
                    'created_at' => now(),
                ]);

                $entries++;
            }
        }

        return ['teams' => $teams, 'entries' => $entries, 'unresolved' => $unresolved];
    }

    /** The core API spells the league out where the site API uses a path. */
    private function leagueFor(Sport $sport): ?string
    {
        $path = $sport->config('espn_path');

        if (! is_string($path) || ! str_contains($path, '/')) {
            return null;
        }

        [$group, $league] = explode('/', $path, 2);

        return $group.'/leagues/'.$league;
    }

    /** The trailing id out of an athlete reference URL. */
    private function athleteIdFrom(?string $ref): ?string
    {
        if ($ref === null) {
            return null;
        }

        if (preg_match('#/athletes/(\d+)#', $ref, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
