<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\UnmatchedName;
use App\Services\Teams\NameNormalizer;
use App\Services\Teams\TeamResolver;
use Illuminate\Support\Str;

/*
 * Team and unmatched-name fixtures, shared by the suites that cover name
 * resolution. They live here rather than in one of those files because a
 * helper declared in a test file exists only while that file happens to be
 * loaded, so the second file to declare the same name kills the whole run.
 */

/** An unmatched name as a feed would have logged it. */
function unmatched(string $raw, string $sport = 'ncaab', int $hits = 3): UnmatchedName
{
    return UnmatchedName::create([
        'sport' => $sport,
        'source' => 'the_odds_api',
        'raw_name' => $raw,
        'norm_name' => NameNormalizer::normalize($raw),
        'hits' => $hits,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

/**
 * A college with no source id.
 *
 * The slug carries a random suffix so a test can create two teams whose
 * names normalise alike without tripping the unique index - which is the
 * situation most of these tests are about.
 */
function college(string $name, string $sport = 'ncaab'): Team
{
    return Team::create([
        'sport' => $sport,
        'name' => $name,
        'display_name' => $name,
        'slug' => Str::slug($name).'-'.Str::random(4),
    ]);
}

/**
 * A college as edge:teams:import leaves it: the real slug, and an ESPN id
 * attached, which is what makes the team findable when its name changes.
 */
function espnTeam(string $displayName, string $espnId, string $sport = 'ncaaf'): Team
{
    $team = Team::create([
        'sport' => $sport,
        'name' => $displayName,
        'display_name' => $displayName,
        'slug' => NameNormalizer::slug($displayName),
    ]);

    app(TeamResolver::class)->linkExternalId($team, 'espn', $espnId);

    return $team;
}
