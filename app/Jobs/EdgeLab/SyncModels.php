<?php

declare(strict_types=1);

namespace App\Jobs\EdgeLab;

use App\Enums\Verdict;
use App\Models\SyncRun;
use App\Models\VlModel;
use App\Services\EdgeLab\EdgeLabClient;
use App\Services\EdgeLab\Mirror;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;

/**
 * Pulls model versions and their verdicts.
 *
 * The most important of the three feeds, because it decides what Vigil may
 * sell. A board entry can say "bet"; only a `proven` verdict here lets it
 * reach a customer as a pick.
 *
 * Mirrored over time rather than overwritten: a verdict changes when a
 * model is retrained, and which verdict was in force when a pick was
 * published is part of the record that the track record rests on.
 */
class SyncModels implements ShouldQueue
{
    use Queueable, SyncsFromEdgeLab;

    public int $tries = 3;

    public int $backoff = 60;

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('edgelab-sync:models'))->expireAfter(600)];
    }

    protected function feed(): string
    {
        return 'models';
    }

    public function handle(EdgeLabClient $client, Mirror $mirror): void
    {
        $this->tracked($client, function (SyncRun $run) use ($client, $mirror): array {
            /*
             * Always from the beginning, not from a cursor.
             *
             * The other feeds are append-only upstream, so an id already
             * seen can never change. A model version is not: its verdict
             * and its metrics are rewritten when it is retrained, and its
             * status flips when another version is activated. A keyset
             * cursor would walk straight past all of that and leave Vigil
             * selling against a verdict that had since been withdrawn.
             */
            $cursor = null;
            $fetched = 0;
            $written = 0;
            $pages = 0;
            $observedAt = now();

            do {
                $page = $client->models($cursor);
                $pages++;
                $fetched += $page->count();

                foreach ($page->rows as $row) {
                    $written += $mirror->write(VlModel::class, $row, $this->columns($row), $observedAt) ? 1 : 0;
                }

                // Paged through within this run, then thrown away: see
                // the note above about why models are never resumed.
                if ($page->nextSince !== null) {
                    $cursor = $page->nextSince;
                }
            } while ($page->hasMore() && $pages < 25);

            // Deliberately not advanced, for the reason above.
            return ['fetched' => $fetched, 'written' => $written, 'cursor' => null, 'pages' => $pages];
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function columns(array $row): array
    {
        return [
            'edge_lab_id' => (int) $row['id'],
            'sport' => $row['sport'] ?? 'unknown',
            'market' => $row['market'] ?? 'unknown',
            'model_type' => $row['model_type'] ?? null,
            'version' => $row['version'] ?? null,
            'status' => $row['status'] ?? null,
            'verdict' => $row['verdict'] ?? null,
            'verdict_reason' => $row['verdict_reason'] ?? null,
            /*
             * Read, never inferred from the metrics beside it - and
             * taken from the enum rather than compared against the
             * string 'proven', which is what this was. A fifth copy of
             * that word, missed when the other four were changed, would
             * have mirrored every calibrated market as allows_picks
             * false and left the board refusing what the engine had
             * just approved.
             */
            'allows_picks' => Verdict::tryFrom((string) ($row['verdict'] ?? ''))
                ?->allowsRecommendation() ?? false,
            'predictions' => $row['predictions'] ?? null,
            'log_loss_improvement' => $row['log_loss_improvement'] ?? null,
            'gate' => $row['gate'] ?? null,
            'trained_at' => isset($row['trained_at']) ? Carbon::parse((string) $row['trained_at']) : null,
        ];
    }
}
