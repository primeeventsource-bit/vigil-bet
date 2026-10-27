<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Record\OpinionRecord;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * How the published opinions have done, in public.
 *
 * Separate page and separate route from /record, which is the proven
 * track record. They make different claims and summing them would
 * destroy the stronger one: a pick says this market beat the closing
 * line, an opinion says only that the stated probability was close to
 * true.
 *
 * Public on purpose. An opinion published without its outcome shown is
 * a claim nobody can check, which is the thing §0.7 exists to prevent,
 * and the reason this page shipped in the same week as the opinions
 * themselves rather than later.
 */
class OpinionRecordController extends Controller
{
    public function __invoke(Request $request, OpinionRecord $record): View
    {
        /*
         * Deliberately ungated.
         *
         * This page is the engine's own work and how it has actually
         * done, which is the thing a subscription buys. It answers to
         * OpinionBar - an opinion is published only once its market's
         * probabilities have been measured and held - and never to the
         * verdict gate, which asks the much stronger question /record
         * claims and this page is at pains to say it does not.
         *
         * Empty rather than absent when there is nothing yet, because
         * that is this codebase's answer everywhere else: an accuracy
         * page with no rows says there is nothing to judge, which is
         * true and checkable, where a 404 says nothing at all.
         */
        $sports = $record->sports();

        // Validated against what exists. A sport nobody has an opinion
        // on would otherwise render an empty page that reads as a record
        // of nothing rather than as a bad filter.
        $sport = in_array($request->query('sport'), $sports, true)
            ? $request->query('sport')
            : null;

        $filters = ['sport' => $sport];

        return view('marketing.opinions', [
            'summary' => $record->summary($filters),
            'calibration' => $record->calibration($filters),
            'bySport' => $record->bySport(),
            'opinions' => $record->opinions($filters),
            'sports' => $sports,
            'filters' => $filters,
        ]);
    }
}
