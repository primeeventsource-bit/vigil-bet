<?php

declare(strict_types=1);

namespace App\Services\Teams;

use App\Enums\Sport;
use App\Models\Team;
use App\Models\TeamAlias;
use App\Models\TeamExternalId;
use App\Models\UnmatchedName;
use Illuminate\Database\QueryException;

/**
 * Resolves a source name or id to a team. Resolution order is strict:
 *
 *   1. external id for that source (exact, the only fully safe match)
 *   2. alias registered for that sport and source
 *   3. alias registered for that sport with no source
 *   4. the team name or abbreviation itself, normalized
 *
 * If none of those hit, the name is logged in unmatched_names and null is
 * returned. The pipeline must never guess which team was meant.
 */
class TeamResolver
{
    /** @var array<string, int|null> */
    private array $memo = [];

    /**
     * A team by the id its source gave it, within one sport.
     *
     * The sport is not optional padding; leaving it out silently attaches
     * games to the wrong teams. ESPN numbers teams per league, so id 14 is
     * a different team in every one of them, and an unscoped lookup hands
     * back whichever league happened to claim that number first. On this
     * database that put the Ottawa Senators and Sacramento State Hornets
     * into an NBA fixture, because the NHL and the college basketball
     * syncs had already taken espn:14 and espn:16.
     *
     * It never shows up as a duplicate row either, which is what made it
     * hard to see: the second league does not write a conflicting row, it
     * just reads the first league's team and moves on.
     */
    public function byExternalId(string $source, string $externalId, Sport|string|null $sport = null): ?Team
    {
        $sportKey = $sport instanceof Sport ? $sport->value : $sport;

        $teamId = TeamExternalId::query()
            ->where('source', $source)
            ->where('external_id', $externalId)
            ->when($sportKey !== null, fn ($query) => $query->where('sport', $sportKey))
            ->value('team_id');

        return $teamId ? Team::find($teamId) : null;
    }

    /**
     * The same question as resolve(), asked without changing anything.
     *
     * resolve() is the pipeline's entry point and it is not a pure read: a
     * miss is logged in unmatched_names and memoised on this instance,
     * which is right when a feed is being ingested and wrong when
     * something merely wants to know. Asking the impure version poisoned
     * the memo with a null, so an alias written a moment later was never
     * seen by the rest of the same process.
     */
    public function matches(Sport|string $sport, ?string $name, string $source, ?string $externalId = null): ?Team
    {
        $sportKey = $sport instanceof Sport ? $sport->value : $sport;

        if ($externalId !== null && $externalId !== '') {
            if ($team = $this->byExternalId($source, $externalId, $sportKey)) {
                return $team;
            }
        }

        if ($name === null || trim($name) === '') {
            return null;
        }

        return $this->lookup($sportKey, $source, NameNormalizer::normalize($name));
    }

    public function resolve(
        Sport|string $sport,
        ?string $name,
        string $source,
        ?string $externalId = null,
        array $context = [],
    ): ?Team {
        $sportKey = $sport instanceof Sport ? $sport->value : $sport;

        if ($externalId !== null && $externalId !== '') {
            if ($team = $this->byExternalId($source, $externalId, $sportKey)) {
                return $team;
            }
        }

        if ($name === null || trim($name) === '') {
            $this->logUnmatched($sportKey, $source, (string) $name, $context);

            return null;
        }

        $norm = NameNormalizer::normalize($name);
        $memoKey = $sportKey.'|'.$source.'|'.$norm;

        if (array_key_exists($memoKey, $this->memo)) {
            $id = $this->memo[$memoKey];

            if ($id !== null) {
                return Team::find($id);
            }

            $this->logUnmatched($sportKey, $source, $name, $context);

            return null;
        }

        $team = $this->lookup($sportKey, $source, $norm);

        $this->memo[$memoKey] = $team?->id;

        if ($team === null) {
            $this->logUnmatched($sportKey, $source, $name, $context);

            return null;
        }

        // Resolved by name, and the source gave us an id: remember it, so
        // the next lookup is an exact id match rather than a name match.
        if ($externalId !== null && $externalId !== '') {
            $this->linkExternalId($team, $source, $externalId);
        }

        return $team;
    }

    /**
     * Register an alias so a name resolves next time without guessing.
     *
     * Forgets what was memoised first. This object is container-scoped, so
     * a process that looked a name up, failed, and then learned the alias
     * would otherwise keep handing back the failure it remembered.
     */
    public function learnAlias(Team $team, string $alias, ?string $source = null, string $origin = 'manual'): TeamAlias
    {
        $this->forget($team->sport->value, $source, $alias);

        return TeamAlias::query()->updateOrCreate(
            [
                'sport' => $team->sport->value,
                'source' => $source,
                'alias_norm' => NameNormalizer::normalize($alias),
            ],
            [
                'alias_raw' => $alias,
                'team_id' => $team->id,
                'origin' => $origin,
            ]
        );
    }

    public function linkExternalId(Team $team, string $source, string $externalId): TeamExternalId
    {
        // Keyed on the sport as well, or linking an NBA team to espn:14
        // reassigns the NHL team that already holds it.
        return TeamExternalId::query()->updateOrCreate(
            ['source' => $source, 'external_id' => $externalId, 'sport' => $team->sport->value],
            ['team_id' => $team->id]
        );
    }

    /**
     * Drop a memoised answer for one name.
     *
     * The memo is keyed on the source as well, and an alias may be learned
     * with no source at all, so this clears every source's entry for the
     * name rather than guessing which one asked.
     */
    private function forget(string $sportKey, ?string $source, string $name): void
    {
        $norm = NameNormalizer::normalize($name);

        foreach (array_keys($this->memo) as $key) {
            if (str_starts_with($key, $sportKey.'|') && str_ends_with($key, '|'.$norm)) {
                unset($this->memo[$key]);
            }
        }
    }

    private function lookup(string $sportKey, string $source, string $norm): ?Team
    {
        $aliasTeamId = TeamAlias::query()
            ->where('sport', $sportKey)
            ->where('alias_norm', $norm)
            ->orderByRaw('CASE WHEN source = ? THEN 0 ELSE 1 END', [$source])
            ->value('team_id');

        if ($aliasTeamId) {
            return Team::find($aliasTeamId);
        }

        return Team::query()
            ->where('sport', $sportKey)
            ->where(function ($q) use ($norm): void {
                $q->where('slug', str_replace(' ', '-', $norm))
                    ->orWhereRaw('LOWER(abbreviation) = ?', [$norm]);
            })
            ->first();
    }

    private function logUnmatched(string $sportKey, string $source, string $raw, array $context): void
    {
        $norm = NameNormalizer::normalize($raw);

        $counted = UnmatchedName::query()
            ->where('sport', $sportKey)
            ->where('source', $source)
            ->where('norm_name', $norm)
            ->increment('hits', 1, ['last_seen_at' => now()]);

        if ($counted > 0) {
            return;
        }

        try {
            UnmatchedName::create([
                'sport' => $sportKey,
                'source' => $source,
                'raw_name' => $raw,
                'norm_name' => $norm,
                'context' => $context ?: null,
                'hits' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        } catch (QueryException) {
            // Another worker logged the same unknown name first.
            UnmatchedName::query()
                ->where('sport', $sportKey)
                ->where('source', $source)
                ->where('norm_name', $norm)
                ->increment('hits', 1, ['last_seen_at' => now()]);
        }
    }
}
