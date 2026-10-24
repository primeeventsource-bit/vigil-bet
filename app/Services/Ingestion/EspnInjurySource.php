<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\InjuryStatus;
use App\Enums\Sport;
use App\Models\InjuryReport;
use App\Models\Team;
use App\Services\Teams\TeamResolver;
use Illuminate\Support\Carbon;

/**
 * Availability reports from ESPN's keyless injuries endpoint.
 *
 * One request answers for a whole league, which is the only reason this is
 * affordable: a per-team endpoint across six sports would be hundreds of
 * calls a day for data that changes a handful of times.
 *
 * Append-only, like every other point-in-time table here, and for the same
 * reason: an injury report is only useful if you can say what was known
 * before kickoff. Overwriting Thursday's questionable with Sunday's active
 * would destroy exactly the fact a model needs.
 *
 * Two deliberate refusals:
 *
 *  - A status this app does not recognise is stored as Unknown with the
 *    source's own wording kept beside it, never mapped to the nearest
 *    guess. Every league invents its own vocabulary and changes it without
 *    notice, and a wrong guess here reads as a confident statement about
 *    whether a player is fit.
 *  - A team name that does not resolve is logged and skipped rather than
 *    attached to the closest match, which is how injuries end up on the
 *    wrong side of a game.
 *
 * Nothing here is deduplicated against the previous pull. A fresh pull of
 * an unchanged report is a new row, because "still questionable on Sunday
 * morning" is a different and more useful fact than "was questionable on
 * Thursday".
 */
class EspnInjurySource
{
    use MakesHttpRequests;

    private const BASE = 'https://site.api.espn.com/apis/site/v2/sports';

    public function __construct(private readonly TeamResolver $teams) {}

    public function key(): string
    {
        return 'espn';
    }

    public function isAvailable(Sport $sport): bool
    {
        return $sport->config('espn_path') !== null;
    }

    public function reliability(): SourceReliability
    {
        return SourceReliability::Unofficial;
    }

    /**
     * Pull every current report for one sport and write them down.
     *
     * @return array{reports: int, teams: int, unresolved: int, unknown_status: int}
     */
    public function sync(Sport $sport): array
    {
        if (! $this->isAvailable($sport)) {
            return ['reports' => 0, 'teams' => 0, 'unresolved' => 0, 'unknown_status' => 0];
        }

        $payload = $this->getJson(self::BASE.'/'.$sport->config('espn_path').'/injuries');

        // The feed stamps the whole response, which is the closest thing to
        // a report time it gives for entries that carry no date of their own.
        $stamped = $this->parseTime($payload['timestamp'] ?? null) ?? now();

        $written = 0;
        $teamsSeen = 0;
        $unresolved = 0;
        $unknown = 0;

        foreach ((array) ($payload['injuries'] ?? []) as $group) {
            $team = $this->resolveTeam($sport, $group);

            if ($team === null) {
                $unresolved++;

                continue;
            }

            $teamsSeen++;

            foreach ((array) ($group['injuries'] ?? []) as $entry) {
                $raw = $entry['status'] ?? null;
                $status = InjuryStatus::fromSourceLabel(is_string($raw) ? $raw : null);

                if ($status === InjuryStatus::Unknown) {
                    $unknown++;
                }

                $name = $entry['athlete']['displayName'] ?? null;

                // A report with no player on it says nothing at all.
                if (! is_string($name) || trim($name) === '') {
                    continue;
                }

                InjuryReport::create([
                    'sport' => $sport->value,
                    'team_id' => $team->id,
                    'event_id' => null,
                    'player_external_id' => $this->athleteId($entry),
                    'player_name' => mb_substr(trim($name), 0, 255),
                    'position' => $this->position($entry),
                    'status' => $status->value,
                    // The source's own wording, kept whatever we mapped it
                    // to, so an unrecognised label can be read back and the
                    // mapping corrected without re-fetching history.
                    'detail' => $this->detail($entry, is_string($raw) ? $raw : null),
                    'source' => $this->key(),
                    'reported_at' => $this->parseTime($entry['date'] ?? null) ?? $stamped,
                    'created_at' => now(),
                ]);

                $written++;
            }
        }

        return [
            'reports' => $written,
            'teams' => $teamsSeen,
            'unresolved' => $unresolved,
            'unknown_status' => $unknown,
        ];
    }

    /** @param  array<string, mixed>  $group */
    private function resolveTeam(Sport $sport, array $group): ?Team
    {
        $name = $group['displayName'] ?? null;
        $externalId = isset($group['id']) ? (string) $group['id'] : null;

        return $this->teams->resolve(
            $sport,
            is_string($name) ? $name : null,
            $this->key(),
            $externalId,
            ['endpoint' => 'injuries'],
        );
    }

    /**
     * ESPN does not put the athlete id on the injury entry; it is only in
     * the player link. Pulled out of there because it is the one stable
     * handle on a player, and names repeat.
     *
     * @param  array<string, mixed>  $entry
     */
    private function athleteId(array $entry): ?string
    {
        foreach ((array) ($entry['athlete']['links'] ?? []) as $link) {
            if (! is_string($link['href'] ?? null)) {
                continue;
            }

            if (preg_match('#/id/(\d+)/#', $link['href'], $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $entry */
    private function position(array $entry): ?string
    {
        $position = $entry['athlete']['position']['abbreviation']
            ?? $entry['athlete']['position']['name']
            ?? null;

        return is_string($position) ? mb_substr($position, 0, 16) : null;
    }

    /** @param  array<string, mixed>  $entry */
    private function detail(array $entry, ?string $rawStatus): ?string
    {
        $parts = array_filter([
            $rawStatus === null ? null : 'source status: '.$rawStatus,
            is_string($entry['type']['description'] ?? null) ? $entry['type']['description'] : null,
            is_string($entry['shortComment'] ?? null) ? $entry['shortComment'] : null,
        ]);

        return $parts === [] ? null : mb_substr(implode(' · ', $parts), 0, 2000);
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
