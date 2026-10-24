<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Sport;
use App\Models\Team;
use App\Models\TeamAlias;
use App\Services\Teams\NameNormalizer;
use App\Services\Teams\TeamResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Imports the team list for a sport from ESPN, which is the only free source
 * that covers college rosters. For the pro leagues this mostly just attaches
 * ESPN ids to teams the seeder already created, which turns later lookups
 * into exact id matches instead of name matches.
 */
class ImportTeamsCommand extends Command
{
    protected $signature = 'edge:teams:import {sport : Sport key, or "all"} {--dry-run}';

    protected $description = 'Import or refresh the team list for a sport from ESPN';

    public function handle(TeamResolver $resolver): int
    {
        $sports = mb_strtolower((string) $this->argument('sport')) === 'all'
            ? Sport::enabled()
            : array_filter([Sport::tryFrom(mb_strtolower((string) $this->argument('sport')))]);

        if ($sports === []) {
            $this->error('Unknown sport. Known: '.implode(', ', Sport::values()).', all');

            return self::FAILURE;
        }

        foreach ($sports as $sport) {
            $path = $sport->config('espn_path');

            if ($path === null) {
                $this->warn("{$sport->value}: no ESPN path configured, skipping.");

                continue;
            }

            $this->line("Importing {$sport->label()} teams...");

            $response = Http::timeout(60)
                ->withUserAgent((string) config('edge.http.user_agent'))
                ->retry(3, 500, throw: false)
                ->get("https://site.api.espn.com/apis/site/v2/sports/{$path}/teams", ['limit' => 1000]);

            if ($response->failed()) {
                $this->error("  ESPN returned {$response->status()}.");

                continue;
            }

            $teams = data_get($response->json(), 'sports.0.leagues.0.teams', []);
            $created = 0;
            $linked = 0;

            foreach ($teams as $entry) {
                $team = $entry['team'] ?? null;

                if (! is_array($team) || ! isset($team['displayName'], $team['id'])) {
                    continue;
                }

                if ($this->option('dry-run')) {
                    continue;
                }

                DB::transaction(function () use ($sport, $team, $resolver, &$created, &$linked): void {
                    // The source id is the identity; the slug is a label.
                    // Looking up by slug alone means any change to how a
                    // name is normalised re-imports every affected team as
                    // a new row - which is exactly what happened the first
                    // time an apostrophe stopped being turned into a space.
                    $model = $resolver->byExternalId('espn', (string) $team['id'], $sport)
                        ?? Team::query()->firstOrNew([
                            'sport' => $sport->value,
                            'slug' => NameNormalizer::slug((string) $team['displayName']),
                        ]);

                    if (! $model->exists) {
                        $created++;
                    }

                    // Kept in step with the name, so a team found by its id
                    // still ends up with the slug the current normalizer
                    // would give it - unless another team already holds
                    // that slug, in which case two real teams share a name
                    // and renaming one on top of the other would be worse
                    // than leaving the label stale.
                    $slug = NameNormalizer::slug((string) $team['displayName']);

                    $taken = Team::query()
                        ->where('sport', $sport->value)
                        ->where('slug', $slug)
                        ->when($model->exists, fn ($q) => $q->whereKeyNot($model->id))
                        ->exists();

                    if ($taken) {
                        $this->warn(sprintf('  %s wants the slug %s, which is taken. Left as %s.', $team['displayName'], $slug, $model->slug));
                    } else {
                        $model->slug = $slug;
                    }

                    $model->fill([
                        'name' => (string) ($team['name'] ?? $team['displayName']),
                        'display_name' => (string) $team['displayName'],
                        'abbreviation' => $team['abbreviation'] ?? null,
                        'active' => (bool) ($team['isActive'] ?? true),
                    ])->save();

                    $resolver->linkExternalId($model, 'espn', (string) $team['id']);
                    $linked++;

                    foreach ([$team['displayName'], $team['shortDisplayName'] ?? null, $team['location'] ?? null, $team['abbreviation'] ?? null] as $alias) {
                        if (! is_string($alias) || trim($alias) === '') {
                            continue;
                        }

                        TeamAlias::query()->updateOrCreate(
                            [
                                'sport' => $sport->value,
                                'source' => 'espn',
                                'alias_norm' => NameNormalizer::normalize($alias),
                            ],
                            ['alias_raw' => $alias, 'team_id' => $model->id, 'origin' => 'auto']
                        );
                    }
                });
            }

            $this->info(sprintf(
                '  %d teams from ESPN, %d new, %d ids linked.%s',
                count($teams),
                $created,
                $linked,
                $this->option('dry-run') ? ' (dry run, nothing written)' : '',
            ));
        }

        return self::SUCCESS;
    }
}
