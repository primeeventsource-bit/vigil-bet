<?php

declare(strict_types=1);

namespace App\Services\Learning;

use App\Models\AiUsage;
use App\Models\SyncRun;
use App\Models\VlBoardEntry;
use App\Models\VlModel;
use Illuminate\Support\Collection;

/**
 * Whether the engine is actually getting better.
 *
 * Everything here is read from model versions Edge Lab wrote. Nothing is
 * scored, re-derived or estimated on this side - a second opinion about
 * whether a model improved is exactly the kind of number that would make
 * this page reassuring and wrong.
 *
 * The honest headline today is that nothing is proven, and the useful
 * part of the page is *why*: which gate each version failed, and whether
 * the failure is getting smaller version over version. A dashboard that
 * only showed a verdict would say "no" fourteen times and teach nobody
 * anything.
 */
class LearningCenter
{
    /**
     * Each sport and market, with every version of its model in order.
     *
     * This is the page's reason to exist. A verdict answers "may this
     * sell"; a trajectory answers "is the work going anywhere", and those
     * are different questions with different answers right now.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function trajectories(): Collection
    {
        return VlModel::query()
            ->current()
            ->get()
            ->sortBy([['sport', 'asc'], ['market', 'asc'], ['trained_at', 'asc'], ['version', 'asc']])
            ->groupBy(fn (VlModel $m) => $m->sport.'|'.$m->market)
            ->map(function (Collection $versions, string $key) {
                [$sport, $market] = explode('|', $key);

                $points = $versions->map(fn (VlModel $m) => [
                    'label' => ($m->model_type ?? 'model').' v'.$m->version,
                    'verdict' => $m->verdict,
                    'improvement' => $m->log_loss_improvement === null ? null : (float) $m->log_loss_improvement,
                    'predictions' => $m->predictions,
                    'status' => $m->status,
                    'trained_at' => $m->trained_at,
                    'reason' => $m->verdict_reason,
                    'calibration_error' => $this->fromPayload($m, 'calibration_error'),
                    'market_calibration_error' => $this->fromPayload($m, 'market_calibration_error'),
                    'brier' => $this->fromPayload($m, 'brier'),
                ])->values();

                return [
                    'sport' => strtoupper($sport),
                    'market' => $market,
                    'points' => $points,
                    'current' => $points->last(),
                    'direction' => $this->direction($points),
                    'best_improvement' => $points->pluck('improvement')->filter(fn ($v) => $v !== null)->max(),
                ];
            })
            ->sortByDesc(fn (array $t) => $t['points']->count())
            ->values();
    }

    /**
     * Which way the measurable thing is moving.
     *
     * Log loss improvement against the closing line, first measured
     * version to last. Null wherever there is nothing to compare - a
     * single version has no trajectory, and saying "flat" would imply it
     * had been measured twice and had not moved.
     */
    private function direction(Collection $points): ?string
    {
        $measured = $points->pluck('improvement')->filter(fn ($v) => $v !== null)->values();

        if ($measured->count() < 2) {
            return null;
        }

        $delta = (float) $measured->last() - (float) $measured->first();

        // A hair either way is noise, not a direction.
        return match (true) {
            $delta > 0.005 => 'improving',
            $delta < -0.005 => 'regressing',
            default => 'flat',
        };
    }

    /** @return array<string, mixed> */
    public function headline(): array
    {
        $models = VlModel::query()->current()->get();

        return [
            'versions' => $models->count(),
            'markets' => $models->groupBy(fn (VlModel $m) => $m->sport.'|'.$m->market)->count(),
            'proven' => $models->where('verdict', 'proven')->count(),
            // Predictions the engine has actually graded itself against.
            // Summed across the newest version of each market, not across
            // all versions, which would count the same games repeatedly.
            'predictions_measured' => $models
                ->groupBy(fn (VlModel $m) => $m->sport.'|'.$m->market)
                ->map(fn (Collection $g) => (int) $g->sortBy('trained_at')->last()?->predictions)
                ->sum(),
            'last_trained_at' => $models->max('trained_at'),
        ];
    }

    /**
     * The gate every version was measured against, and the count failing
     * for each reason.
     *
     * @return array<string, mixed>
     */
    public function gate(): array
    {
        $models = VlModel::query()->current()->get();

        return [
            'thresholds' => $models->first()?->gate ?? [],
            'verdicts' => $models->groupBy('verdict')->map->count()->sortDesc(),
        ];
    }

    /**
     * What the board is made of, and why nothing is being sold from it.
     *
     * @return array<string, mixed>
     */
    public function board(): array
    {
        $entries = VlBoardEntry::query()->current();

        return [
            'total' => (clone $entries)->count(),
            'picks' => (clone $entries)->sellablePicks()->count(),
            'pass_reasons' => (clone $entries)->passes()
                ->selectRaw('pass_reason, count(*) as n')
                ->groupBy('pass_reason')
                ->orderByDesc('n')
                ->pluck('n', 'pass_reason'),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function feeds(): Collection
    {
        return collect(['board', 'events', 'models'])->map(function (string $feed) {
            $last = SyncRun::lastSuccessFor($feed);

            return [
                'feed' => $feed,
                'last_success_at' => $last?->finished_at,
                'records' => $last?->records_written,
                'failures_24h' => SyncRun::query()->feed($feed)->failed()->recent24h()->count(),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function aiSpend(): array
    {
        $month = AiUsage::query()->thisMonth();

        return [
            'calls' => (clone $month)->count(),
            'rejected' => (clone $month)->where('accepted', false)->count(),
            'cost' => AiUsage::dollars((int) (clone $month)->sum('cost_millicents')),
        ];
    }

    private function fromPayload(VlModel $model, string $key): ?float
    {
        $value = $model->payload[$key] ?? null;

        return $value === null ? null : (float) $value;
    }
}
