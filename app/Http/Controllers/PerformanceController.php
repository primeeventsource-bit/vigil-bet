<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserBet;
use App\Services\Bets\BetRecord;
use App\Services\Record\OpinionRecord;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Results and performance: a customer's own record, and the engine's.
 *
 * Replaces two routes that rendered app/placeholder.blade.php - one
 * saying "Not built yet - Step 8" and the other "Not built yet - Step
 * 9". Everything needed to build them already existed: BetRecord has
 * computed win rate, ROI, units and closing line value since bets were
 * first tracked, and the figures were visible only as four tiles on the
 * bet log.
 *
 * Two records, side by side, because they answer different questions and
 * are easy to confuse. "How have the engine's published opinions done"
 * is the product's claim about itself and is public. "How have I done"
 * is the customer's own record of bets they entered by hand, and the
 * product has no way to verify any of it - which the page says, because
 * a number presented without that caveat reads as something the product
 * is standing behind.
 */
class PerformanceController extends Controller
{
    public function __invoke(Request $request, BetRecord $bets, OpinionRecord $opinions): View
    {
        $user = $request->user();

        $record = $bets->forUser($user);

        return view('app.performance', [
            'record' => $record,
            'curve' => $bets->curve($user),

            /*
             * The engine's own published record, for the comparison
             * that is actually interesting: a customer beating it and a
             * customer losing to it are both worth knowing, and neither
             * is visible from one record alone.
             */
            'engine' => $opinions->summary(),

            /*
             * Broken down by sport, because "I am down four units" and
             * "I am down four units entirely on one league" are
             * different facts and only the second is actionable.
             */
            'bySport' => UserBet::query()
                ->where('user_id', $user->id)
                ->settled()
                ->selectRaw('sport, count(*) as n')
                ->selectRaw('sum(case when status = ? then 1 else 0 end) as won', ['won'])
                ->selectRaw('sum(case when status = ? then 1 else 0 end) as lost', ['lost'])
                ->selectRaw('sum(stake_cents) as staked')
                ->selectRaw('sum(return_cents) as returned')
                ->groupBy('sport')
                ->orderByDesc('n')
                ->get(),

            /*
             * CLV is the one figure on this page that is not a result.
             * It says whether a bet beat the number the market closed
             * at, which is the thing that predicts a long run better
             * than a short one's profit does - so it is shown even when
             * the profit is negative, and especially then.
             */
            'showClv' => $request->user()->can('bets.clv'),
        ]);
    }
}
