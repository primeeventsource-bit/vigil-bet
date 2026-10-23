<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Entitlements\Entitlements;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Where a customer lands when they ask for something their plan does not
 * include.
 *
 * Named, not generic. "Upgrade to see this" tells somebody nothing; the
 * page says which feature they reached for, which tier includes it, and
 * what that tier costs. A paywall that hides what is behind it is asking
 * for money on trust.
 */
class UpgradeController extends Controller
{
    /** Human wording for each feature key, so a URL fragment never reaches a customer. */
    private const LABELS = [
        'board.full' => 'the full value board, with every pick and every pass',
        'board.today' => "today's odds board",
        'card.daily' => 'the daily card',
        'read.view' => 'explanations of picks and passes',
        'bets.track' => 'the bet tracker',
        'bets.roi' => 'win/loss and ROI on your bets',
        'bets.clv' => 'closing line value on your own bets',
        'parlay.grade' => 'the parlay grader',
        'alerts' => 'alerts when a pick posts',
        'lineshopping' => 'line shopping across every tracked book',
        'props' => 'the player props board',
        'tools.crossbook' => 'the arbitrage and middle finders',
        'modellab' => 'Model Lab',
        'books.custom' => 'your own list of books',
        'bankroll' => 'bankroll and Kelly sizing',
    ];

    public function __invoke(Request $request, Entitlements $entitlements): View
    {
        $feature = (string) $request->query('feature', '');
        $required = $entitlements->tierRequiredFor($feature);

        return view('app.upgrade', [
            'feature' => $feature,
            'label' => self::LABELS[$feature] ?? null,
            'requiredTier' => $required,
            'tier' => $required === null ? null : config('plans.tiers.'.$required),
            'currentTier' => $entitlements->tier($request->user()),
        ]);
    }
}
