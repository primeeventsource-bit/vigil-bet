<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Sport;
use App\Models\Team;
use App\Models\TeamAlias;
use App\Services\Teams\NameNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the four pro leagues from static data so the app resolves team names
 * with no API keys at all. College teams are too numerous and too fluid to
 * hardcode; they come from edge:teams:import.
 *
 * Aliases include former names and the abbreviations historical datasets
 * still use, so a 2014 game row joins to the right franchise instead of
 * being logged as unmatched.
 */
class TeamSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([Sport::Nfl, Sport::Nba, Sport::Mlb, Sport::Nhl] as $sport) {
            $path = database_path("data/teams_{$sport->value}.php");

            if (! file_exists($path)) {
                continue;
            }

            foreach (require $path as [$abbr, $displayName, $conference, $division, $aliases]) {
                DB::transaction(function () use ($sport, $abbr, $displayName, $conference, $division, $aliases): void {
                    $team = Team::query()->updateOrCreate(
                        ['sport' => $sport->value, 'slug' => NameNormalizer::slug($displayName)],
                        [
                            'name' => $displayName,
                            'display_name' => $displayName,
                            'abbreviation' => $abbr,
                            'conference' => $conference,
                            'division' => $division,
                            'active' => true,
                        ]
                    );

                    $all = array_merge([$displayName, $abbr], $aliases);

                    foreach (array_unique($all) as $alias) {
                        if (trim((string) $alias) === '') {
                            continue;
                        }

                        TeamAlias::query()->updateOrCreate(
                            [
                                'sport' => $sport->value,
                                'source' => null,
                                'alias_norm' => NameNormalizer::normalize((string) $alias),
                            ],
                            [
                                'alias_raw' => (string) $alias,
                                'team_id' => $team->id,
                                'origin' => 'seed',
                            ]
                        );
                    }
                });
            }

            $this->command?->info(sprintf('%s teams seeded.', $sport->label()));
        }
    }
}
