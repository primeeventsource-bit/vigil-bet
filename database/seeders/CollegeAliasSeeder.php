<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Sport;
use App\Models\Team;
use App\Models\TeamAlias;
use App\Models\UnmatchedName;
use App\Services\Teams\NameNormalizer;
use Illuminate\Database\Seeder;

/**
 * Writes the curated college aliases, for the handful of names that no rule
 * reconciles.
 *
 * Runs after the colleges themselves exist, which is edge:teams:import, not
 * a seeder - so on a fresh install this finds nothing and says so rather
 * than failing. Re-running it once the import has happened is the whole
 * intended workflow.
 */
class CollegeAliasSeeder extends Seeder
{
    public function run(): void
    {
        $rows = require database_path('data/college_aliases.php');

        $written = 0;
        $missing = [];

        foreach ($rows as [$sportKey, $feedName, $espnName]) {
            $sport = Sport::tryFrom($sportKey);

            if ($sport === null) {
                continue;
            }

            $team = Team::query()
                ->where('sport', $sport->value)
                ->where('slug', NameNormalizer::slug($espnName))
                ->first();

            if ($team === null) {
                $missing[] = $espnName;

                continue;
            }

            // Source-less, so it answers for any feed that sends this
            // spelling rather than only the one that happened to send it
            // first.
            TeamAlias::query()->updateOrCreate(
                [
                    'sport' => $sport->value,
                    'source' => null,
                    'alias_norm' => NameNormalizer::normalize($feedName),
                ],
                [
                    'alias_raw' => $feedName,
                    'team_id' => $team->id,
                    'origin' => 'seed',
                ]
            );

            // The log exists to be worked through, not to accumulate. A row
            // still sitting there after its alias is written would read as
            // an open problem.
            UnmatchedName::query()
                ->where('sport', $sport->value)
                ->where('norm_name', NameNormalizer::normalize($feedName))
                ->delete();

            $written++;
        }

        $this->command?->info(sprintf('%d college alias(es) written.', $written));

        if ($missing !== []) {
            $this->command?->warn(sprintf(
                'Not found, so skipped: %s. Run edge:teams:import first.',
                implode(', ', $missing),
            ));
        }
    }
}
