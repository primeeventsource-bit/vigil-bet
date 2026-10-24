<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\InjuryStatus;
use App\Enums\Sport;
use App\Models\InjuryReport;
use App\Models\Team;
use App\Services\Teams\TeamResolver;

/**
 * NBA availability reports from balldontlie.
 *
 * Paid, and NBA only on this account - the NFL and MLB endpoints are
 * separate subscriptions and answer 401. What the money buys is not data
 * that did not exist: ESPN was already serving NBA injuries and still is
 * for every other sport here. It buys a documented, keyed, paginated feed
 * with stable player ids and a stated return date, in place of an
 * undocumented endpoint that can change shape without anyone being told.
 *
 * The same two refusals as every other availability source, for the same
 * reasons:
 *
 *  - A status this app does not recognise is stored as Unknown with the
 *    source's own wording kept beside it. The three labels this feed
 *    publishes - Out, Day-To-Day and Questionable - are all already
 *    mapped, which was checked against live responses rather than
 *    assumed, but the next one it invents must not be guessed at.
 *  - A team that does not resolve is logged and skipped, never attached to
 *    the closest match.
 *
 * Nothing is deduplicated against the previous pull. A fresh pull of an
 * unchanged report is a new row, because "still day-to-day this morning"
 * is a different fact from "was day-to-day on Tuesday", and the table is
 * append-only so that the difference survives.
 */
class BalldontlieInjurySource
{
    use MakesHttpRequests;

    /**
     * Pages to walk before giving up.
     *
     * The feed returns about 70 reports in a page of 100, so one page is
     * the whole league today. The cap is here so a cursor that stops
     * advancing cannot spin forever against a metered paid API.
     */
    private const MAX_PAGES = 20;

    public function __construct(private readonly TeamResolver $teams) {}

    public function key(): string
    {
        return 'balldontlie';
    }

    public function isAvailable(Sport $sport): bool
    {
        return $sport === Sport::Nba && filled(config('edge.sources.balldontlie.key'));
    }

    public function reliability(): SourceReliability
    {
        return SourceReliability::DocumentedPublic;
    }

    /**
     * Pull every current NBA report and write them down.
     *
     * @return array{reports: int, teams: int, unresolved: int, unknown_status: int}
     */
    public function sync(Sport $sport): array
    {
        $empty = ['reports' => 0, 'teams' => 0, 'unresolved' => 0, 'unknown_status' => 0];

        if (! $this->isAvailable($sport)) {
            return $empty;
        }

        $teamNames = $this->teamNames();

        $written = 0;
        $unresolved = 0;
        $unknown = 0;
        $teamsSeen = [];

        // One pull time for the whole run. The feed states no report time -
        // only a forecast return date - so the honest stamp is when we
        // asked, and every row in a run was known at the same moment.
        $pulledAt = now();

        foreach ($this->pages('player_injuries') as $row) {
            $player = is_array($row['player'] ?? null) ? $row['player'] : [];

            $name = trim(($player['first_name'] ?? '').' '.($player['last_name'] ?? ''));

            // A report with no player on it says nothing at all.
            if ($name === '') {
                continue;
            }

            $team = $this->resolveTeam($player['team_id'] ?? null, $teamNames);

            if ($team === null) {
                $unresolved++;

                continue;
            }

            $teamsSeen[$team->id] = true;

            $raw = is_string($row['status'] ?? null) ? $row['status'] : null;
            $status = InjuryStatus::fromSourceLabel($raw);

            if ($status === InjuryStatus::Unknown) {
                $unknown++;
            }

            InjuryReport::create([
                'sport' => $sport->value,
                'team_id' => $team->id,
                'event_id' => null,
                'player_external_id' => isset($player['id']) ? (string) $player['id'] : null,
                'player_name' => mb_substr($name, 0, 255),
                'position' => is_string($player['position'] ?? null)
                    ? mb_substr($player['position'], 0, 16)
                    : null,
                'status' => $status->value,
                'detail' => $this->detail($row, $raw),
                'source' => $this->key(),
                'reported_at' => $pulledAt,
                'created_at' => now(),
            ]);

            $written++;
        }

        return [
            'reports' => $written,
            'teams' => count($teamsSeen),
            'unresolved' => $unresolved,
            'unknown_status' => $unknown,
        ];
    }

    /**
     * Walk a cursor-paginated endpoint.
     *
     * @return iterable<int, array<string, mixed>>
     */
    private function pages(string $path): iterable
    {
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $payload = $this->getJson(
                $this->url($path),
                array_filter(['per_page' => 100, 'cursor' => $cursor]),
                ['Authorization' => (string) config('edge.sources.balldontlie.key')],
            );

            foreach ((array) ($payload['data'] ?? []) as $row) {
                if (is_array($row)) {
                    yield $row;
                }
            }

            $next = $payload['meta']['next_cursor'] ?? null;

            // No cursor, or one that has not moved: either way there is
            // nothing further to ask for, and asking again would be a paid
            // request for a page already read.
            if ($next === null || $next === $cursor) {
                return;
            }

            $cursor = $next;
        }
    }

    /**
     * balldontlie's team ids mapped to the names it calls them.
     *
     * One request, once per sync. The reports carry only the id, and an id
     * means nothing to the resolver until something says which team it is.
     *
     * @return array<int, string>
     */
    private function teamNames(): array
    {
        $payload = $this->getJson(
            $this->url('teams'),
            [],
            ['Authorization' => (string) config('edge.sources.balldontlie.key')],
        );

        $names = [];

        foreach ((array) ($payload['data'] ?? []) as $team) {
            if (! is_array($team) || ! isset($team['id'])) {
                continue;
            }

            $name = $team['full_name'] ?? $team['name'] ?? null;

            if (is_string($name) && trim($name) !== '') {
                $names[(int) $team['id']] = $name;
            }
        }

        return $names;
    }

    /** @param  array<int, string>  $teamNames */
    private function resolveTeam(mixed $teamId, array $teamNames): ?Team
    {
        if ($teamId === null) {
            return null;
        }

        $id = (int) $teamId;

        return $this->teams->resolve(
            Sport::Nba,
            $teamNames[$id] ?? null,
            $this->key(),
            (string) $id,
            ['endpoint' => 'player_injuries'],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function detail(array $row, ?string $rawStatus): ?string
    {
        $parts = array_filter([
            $rawStatus === null ? null : 'source status: '.$rawStatus,
            // A forecast, not a fact, so it is labelled as one.
            is_string($row['return_date'] ?? null) && $row['return_date'] !== ''
                ? 'expected back '.$row['return_date']
                : null,
            is_string($row['description'] ?? null) ? $row['description'] : null,
        ]);

        return $parts === [] ? null : mb_substr(implode(' · ', $parts), 0, 2000);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('edge.sources.balldontlie.base_url'), '/').'/'.ltrim($path, '/');
    }
}
