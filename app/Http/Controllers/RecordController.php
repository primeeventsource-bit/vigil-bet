<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Opinion;
use App\Models\PublishedPick;
use App\Models\VlModel;
use App\Services\Record\OpinionRecord;
use App\Services\Record\PublicRecord;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public track record. Indexable, filterable, and permanent.
 *
 * §7 calls it the single most important trust asset, and the reason is
 * that it is the only page on the site a sceptic will take seriously. So
 * it shows every pick - the losers, the pushes and the ones still running
 * - and every rate carries its sample size or is not shown at all.
 */
class RecordController extends Controller
{
    public function index(Request $request, PublicRecord $record, OpinionRecord $opinions): View
    {
        /*
         * Open when there is something true to show, which is either a
         * market publishing now or picks that were published before.
         *
         * This used to be `anyMarketProven()` alone, and the reasoning
         * was sound: a track record quoting a win rate while no market
         * had cleared the gate reads as "we beat the book" however
         * carefully it is worded. But the gate later tightened to admit
         * only proven markets, and eight picks that had been published
         * and graded under the old bar went with it. A real record of
         * real games became a 404, which is not caution - the games
         * happened, the picks were published before kickoff, and the
         * results are what they are.
         *
         * So the page opens, and `historical` below is what keeps the
         * original promise: when nothing is publishing today, the page
         * says so at the top, before any figure.
         */
        abort_unless(VlModel::anyMarketProven() || PublishedPick::anyPublished(), 404);

        $historical = ! VlModel::anyMarketProven();

        $options = $record->filterOptions();

        /*
         * Filters validated against what exists rather than against a
         * pattern. A month nobody published in would produce an empty
         * page that looked like a record of nothing.
         */
        $filters = [
            'sport' => $this->oneOf($request->query('sport'), $options['sports']->all()),
            'market' => $this->oneOf($request->query('market'), $options['markets']->all()),
            'month' => $this->oneOf($request->query('month'), $options['months']->all()),
        ];

        return view('marketing.record', [
            'summary' => $record->summary($filters),
            'picks' => $record->picks($filters),
            'curve' => $record->curve($filters),
            'options' => $options,
            'filters' => $filters,

            /*
             * True when no market currently clears the gate, so every
             * figure on this page is about picks from markets that are
             * not publishing now. The view leads with that rather than
             * footnoting it - a caveat below a win rate is a caveat
             * most readers will never reach.
             */
            'historical' => $historical,
            // Kept for the hero strip on the home page, which reads the
            // unfiltered figures.
            'overall' => $record->summary(),

            /*
             * The published opinions, on the page a visitor reaches by
             * clicking "Track record".
             *
             * Without them this page says there is nothing and there
             * will be nothing until a market clears the gate - true of
             * the proven tier, and badly misleading beside a product
             * that publishes picks every day and grades all of them. A
             * record page that reports none of what was published is not
             * being careful, it is being wrong.
             *
             * They are passed as their own figures and rendered in their
             * own section. The two are never added together: a pick from
             * a proven market is a stronger claim than an opinion, and
             * summing them would destroy the stronger one.
             */
            'opinionRecord' => $opinions->summary(),
            'opinions' => Opinion::query()
                ->with('result')
                ->orderByDesc('kickoff_at')
                ->limit(20)
                ->get(),
        ]);
    }

    /**
     * One pick, for ever.
     *
     * §7 asks for a permanent URL per pick. Keyed on the ULID rather
     * than the row id, so the link survives this table being rebuilt
     * from its own payloads.
     */
    public function show(PublishedPick $pick): View
    {
        /*
         * The permalink follows the page. §7 asks for one address per
         * pick that never moves, and a pick that was published before
         * kickoff and graded afterwards has a permanent fact attached
         * to it whatever the gate says this week.
         *
         * It carries the same flag, so a pick from a market that is no
         * longer publishing says so on its own page rather than only on
         * the index somebody may not have come from.
         */
        abort_unless(VlModel::anyMarketProven() || PublishedPick::anyPublished(), 404);

        return view('marketing.pick', [
            'pick' => $pick->load('result'),
            'historical' => ! VlModel::anyMarketProven(),
        ]);
    }

    /** @param  array<int, string>  $allowed */
    private function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }
}
