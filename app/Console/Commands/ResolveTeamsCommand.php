<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\UnmatchedName;
use App\Services\Teams\NameNormalizer;
use App\Services\Teams\TeamResolver;
use Illuminate\Console\Command;

/**
 * Works through the unmatched name log and aliases what can be resolved
 * without guessing.
 *
 * The rule that keeps this honest: a name is only linked when exactly one
 * team in that sport is a credible match. One candidate is a fact; two
 * candidates is a coin flip, and a wrong alias silently attaches odds to
 * the wrong team for the rest of time. Anything ambiguous is printed for a
 * human to decide.
 */
class ResolveTeamsCommand extends Command
{
    protected $signature = 'edge:teams:resolve
                            {--sport= : Limit to one sport}
                            {--apply : Write the aliases; without this it only reports}
                            {--limit=200 : How many unmatched names to consider}';

    protected $description = 'Alias unmatched team names where exactly one team matches';

    public function handle(TeamResolver $resolver): int
    {
        $pending = UnmatchedName::query()
            ->when($this->option('sport'), fn ($q) => $q->where('sport', $this->option('sport')))
            ->orderByDesc('hits')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Nothing unmatched.');

            return self::SUCCESS;
        }

        $linked = 0;
        $stale = 0;
        $ambiguous = [];
        $unknown = [];

        foreach ($pending as $row) {
            // A name can start resolving without anything being aliased -
            // the team gets imported, or the normalizer stops splitting a
            // word. The row then sits here forever describing a problem
            // that no longer exists, which is worse than useless in a log
            // whose whole job is to list what still needs attention.
            if ($resolver->matches($row->sport, $row->raw_name, $row->source) !== null) {
                if ($this->option('apply')) {
                    $row->delete();
                }

                $stale++;
                $this->line(sprintf('  <fg=green>ok</>   %-34s resolves now, no alias needed', $row->raw_name));

                continue;
            }

            $candidates = $this->candidatesFor($row->sport->value, $row->raw_name);

            if (count($candidates) === 1) {
                if ($this->option('apply')) {
                    $resolver->learnAlias($candidates[0], $row->raw_name, $row->source, 'auto');
                    $row->delete();
                }

                $linked++;
                $this->line(sprintf('  <fg=green>link</> %-34s -> %s', $row->raw_name, $candidates[0]->display_name));

                continue;
            }

            if ($candidates === []) {
                $unknown[] = $row;

                continue;
            }

            $ambiguous[] = [$row, $candidates];
        }

        $this->newLine();

        if ($ambiguous !== []) {
            $this->line('<comment>Ambiguous, left alone</comment>');

            foreach (array_slice($ambiguous, 0, 15) as [$row, $candidates]) {
                $this->line(sprintf(
                    '  %-34s could be %s',
                    $row->raw_name,
                    implode(' / ', array_map(fn (Team $t) => $t->display_name, array_slice($candidates, 0, 3))),
                ));
            }
        }

        if ($unknown !== []) {
            $this->newLine();
            $this->line('<comment>No candidate at all</comment>');

            foreach (array_slice($unknown, 0, 15) as $row) {
                $this->line('  '.$row->raw_name);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%d resolvable, %d already resolving, %d ambiguous, %d unknown.%s',
            $linked,
            $stale,
            count($ambiguous),
            count($unknown),
            $this->option('apply') ? ' Log updated.' : ' Run with --apply to write them.',
        ));

        return self::SUCCESS;
    }

    /**
     * Teams whose name accounts for every significant word of the unmatched
     * one.
     *
     * Significant means longer than two characters, which drops the of, at
     * and st that otherwise make everything look related.
     *
     * This widens the match in one direction only, and the "exactly one
     * candidate" rule above is what makes that safe: widening can turn a
     * miss into a link or into an ambiguity, and an ambiguity is left for a
     * human. It cannot turn a miss into a wrong link on its own.
     *
     * @return array<int, Team>
     */
    private function candidatesFor(string $sport, string $raw): array
    {
        $words = array_values(array_filter(
            explode(' ', NameNormalizer::normalize($raw)),
            fn (string $w) => mb_strlen($w) > 2,
        ));

        if ($words === []) {
            return [];
        }

        return Team::query()
            ->where('sport', $sport)
            ->get()
            ->filter(function (Team $team) use ($words): bool {
                $target = NameNormalizer::normalize($team->display_name);
                $targetWords = explode(' ', $target);

                foreach ($words as $word) {
                    if (! $this->accountedFor($word, $target, $targetWords)) {
                        return false;
                    }
                }

                return true;
            })
            ->values()
            ->all();
    }

    /**
     * Whether one word of the unmatched name appears in the target.
     *
     * This used to also accept a target word that merely started the same
     * way, on the theory that feeds disagree about how much of a college's
     * name to write out and the disagreement is usually a truncation. It
     * is sometimes a truncation. It is also sometimes a coincidence, and
     * nothing in the name tells the two apart.
     *
     * Run against the real log it produced two correct links - App State
     * for Appalachian State, Southern Miss for Southern Mississippi - and
     * two wrong ones: "north" starts "northwest", so Northwest University
     * Eagles became North Carolina Central Eagles, and "state" starts
     * "statesmen", so Delta State Statesmen became Mississippi Valley
     * State Delta Devils. Both passed the exactly-one-candidate rule,
     * because widening is what made them the one candidate.
     *
     * That is a bad trade at any ratio. A missing alias is a line in a
     * report somebody reads; a wrong one silently attaches odds to the
     * wrong team for the rest of time. The two correct links are in
     * database/data/college_aliases.php instead, where a person checked
     * them.
     *
     * @param  array<int, string>  $targetWords
     */
    private function accountedFor(string $word, string $target, array $targetWords): bool
    {
        return str_contains($target, $word);
    }
}
