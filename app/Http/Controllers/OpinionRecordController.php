<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VlModel;
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
         * Same gate as /record. An opinion is a weaker claim than a
         * pick, but it is still the engine's own selection on a page a
         * stranger can read, and none of it belongs on the site while
         * nothing has passed.
         */
        abort_unless(VlModel::anyMarketProven(), 404);

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
