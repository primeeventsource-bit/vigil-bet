<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VlBoardEntry;
use App\Models\VlModel;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Model Lab: which markets are proven, and the gate each was measured
 * against.
 *
 * §8 calls this the "transparency page", and that is exactly its job. The
 * product's central claim is that it only sells from markets that have
 * beaten the closing line. A claim like that is worth nothing unless the
 * customer can see which markets passed, which failed, by how much, and
 * against what threshold - including the failures, especially the
 * failures.
 *
 * So nothing here is filtered to flatter. A market with a negative
 * verdict appears with its number.
 */
class ModelLabController extends Controller
{
    public function __invoke(): View
    {
        $models = VlModel::query()->current()
            ->orderBy('sport')
            ->orderBy('market')
            ->get();

        return view('app.models', [
            'models' => $models,
            'byVerdict' => $models->groupBy('verdict')->map->count(),
            'sellable' => $models->filter->allowsPicks()->count(),
            // The thresholds in force, read off the models themselves
            // rather than restated here - a gate written out twice is a
            // gate that will eventually disagree with itself.
            'gate' => $this->gateInForce($models),
            'passReasons' => $this->passReasons(),
            'calibration' => null,
        ]);
    }

    /**
     * The thresholds, if every model agrees on them.
     *
     * Null when they differ, rather than showing the first one as though
     * it applied to all: a page that stated one gate while four models
     * were measured against another would be the opposite of a
     * transparency page.
     *
     * @param  Collection<int, VlModel>  $models
     * @return array<string, mixed>|null
     */
    private function gateInForce(Collection $models): ?array
    {
        $gates = $models->pluck('gate')->filter(fn ($g) => is_array($g) && $g !== [])->values();

        if ($gates->isEmpty()) {
            return null;
        }

        return $gates->unique(fn (array $g) => json_encode($g))->count() === 1
            ? $gates->first()
            : null;
    }

    /**
     * Why candidates were declined, counted.
     *
     * The concrete answer to "why is nothing being sold today", which is
     * the question this page exists to answer and the one a customer
     * will ask first.
     *
     * @return Collection<string, int>
     */
    private function passReasons(): Collection
    {
        return VlBoardEntry::query()
            ->current()
            ->whereNotNull('pass_reason')
            ->get()
            ->groupBy('pass_reason')
            ->map->count()
            ->sortDesc();
    }
}
