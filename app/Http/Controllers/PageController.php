<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Opinion;
use App\Models\VlBoardEntry;
use App\Models\VlModel;
use App\Services\EdgeLab\SyncHealth;
use App\Services\Entitlements\Entitlements;
use App\Services\Odds\GameMarkets;
use App\Services\Record\OpinionRecord;
use App\Services\Record\PublicRecord;
use Illuminate\View\View;

/**
 * The flat pages: marketing and legal.
 *
 * Blade templates and no Livewire, deliberately. These are the pages that
 * have to be fast, indexable and readable with JavaScript switched off,
 * and none of them has any state to hold.
 */
class PageController extends Controller
{
    /**
     * The legal pages, named once.
     *
     * This list used to be written out three times - the route
     * constraint, the loop that names each route individually, and the
     * guard in legal() - which meant adding a page in two of the three
     * places produced a 404 that looked like a missing view.
     *
     * @var array<int, string>
     */
    public const LEGAL_PAGES = [
        'terms',
        'privacy',
        'responsible-gambling',
        'disclaimer',
        // The billing rules were correct and buried four headings into
        // the terms. Same rules, findable before somebody buys.
        'cancellation',
    ];

    public function home(OpinionRecord $opinions, GameMarkets $markets): View
    {
        /*
         * The ticker, which the reference layout draws as "LIVE
         * SURVEILLANCE FEED" over Bills vs Chiefs at odds somebody typed
         * in. None of that was data. These are real upcoming games at
         * real consensus prices, and if there are none the band does not
         * render at all - an empty ticker says the feed is broken, and a
         * ticker with invented games in it says something worse.
         *
         * A short window on purpose. The board holds 8,000 scheduled
         * fixtures; a strip across the top of a marketing page is about
         * what is on tonight, not about March.
         */
        $priced = $markets->upcoming(null, 36)
            ->filter(static fn (array $game): bool => ($game['markets']['h2h'] ?? []) !== [])
            ->values();

        $ticker = $priced->take(12)->values();

        /*
         * The same fixtures again, deeper, for the command centre.
         *
         * One query's worth of work serves both: the strip across the
         * top is about what is on tonight, and the board below it is
         * the thing a visitor filters and reads. Pulling the window
         * twice would be two round trips for one answer.
         */
        $board = $priced->take(18)->values();

        /*
         * The leagues that actually have something on the board, in the
         * order the board shows them. Derived from the games rather
         * than from a list of sports the product supports - a filter
         * chip that matches nothing is a dead control, and an empty
         * slate is exactly when somebody is most likely to press it.
         */
        $leagues = $board->pluck('sport')->unique()->values();

        /*
         * Whether the engine has published a call on each of those
         * games, keyed by event.
         *
         * This is the honest answer to "AI evaluation status" per game:
         * an opinion exists or it does not. The alternative - a
         * confidence number beside every fixture - would mean inventing
         * one for the games the engine passed on, which is most of
         * them, and those passes are the product's actual claim.
         */
        $boardOpinions = $board->isEmpty()
            ? collect()
            : Opinion::query()
                ->whereIn('event_id', $board->pluck('id')->all())
                ->get(['id', 'public_id', 'event_id', 'selection', 'price_american'])
                ->keyBy('event_id');

        /*
         * The three steps live here rather than in the template because
         * they are copy, and copy that sits in a Blade loop is copy nobody
         * can find when it needs changing.
         */
        return view('marketing.home', [
            'record' => $this->publicRecord(),

            'ticker' => $ticker,
            'tickerPricedAt' => $ticker->isEmpty() ? null : $markets->lastPricedAt(),

            'board' => $board,
            'leagues' => $leagues,
            'boardOpinions' => $boardOpinions,
            'boardPricedAt' => $board->isEmpty() ? null : $markets->lastPricedAt(),

            /*
             * The callout ribbon from the reference layout. Copy lives
             * here for the same reason the three steps do, and every line
             * of it names something that exists: the engine publishes
             * opinions, the odds are pulled from every tracked book, bets
             * are recorded and settled, and parlays are built and graded.
             * The reference ribbon's sixth claim was steam alerts over
             * Telegram, which is why there are five of these.
             */
            'features' => [
                ['icon' => 'cpu', 'heading' => 'AI predictions', 'body' => 'Independent analysis'],
                ['icon' => 'activity', 'heading' => 'Real-time odds', 'body' => 'Live market data'],
                ['icon' => 'chart', 'heading' => 'Track your bets', 'body' => 'Full performance history'],
                ['icon' => 'layers', 'heading' => 'Build parlays', 'body' => 'Create and record'],
                ['icon' => 'target', 'heading' => 'Data-driven edge', 'body' => 'Not guesswork'],
            ],

            /*
             * Today's published opinions, on the front page.
             *
             * The brief asks for the daily picks to be the first thing a
             * visitor sees, and until now the front page described the
             * product at length without ever showing it. Live only: a
             * game that has started is not a pick anybody can act on.
             */
            'opinions' => Opinion::query()->live()->limit(5)->get(),

            /*
             * And how the published ones have done, in the same breath.
             * A picks list on a marketing page with no record beside it
             * is the shape of every tout site there has ever been.
             */
            'opinionRecord' => $opinions->summary(),
            /*
             * Four, named for what each stage does.
             *
             * It was three, ending at "we say pick or pass" - which left
             * the loop open at exactly the point the product is unusual.
             * Grading every published call against the closing line, and
             * retraining off the result, is the part that earns the
             * right to a verdict at all; stopping at the pick described
             * a tipster instead.
             *
             * Every line is a thing that runs: the odds pull, the
             * no-vig fair probability, the opinion grader, and the
             * weekly retrain that can withdraw a verdict as well as
             * grant one.
             */
            'steps' => [
                [
                    'label' => 'Analyze',
                    'heading' => 'We read the whole market',
                    'body' => 'Every tracked book, every ten minutes near kickoff, so the price on the screen is the price that is actually there.',
                ],
                [
                    'label' => 'Evaluate',
                    'heading' => 'We strip out the vig',
                    'body' => "A fair probability from the market, and the model's probability beside it. The gap is the edge, and most of the time there isn't one.",
                ],
                [
                    'label' => 'Track',
                    'heading' => 'We grade our own calls',
                    'body' => 'Every published call is measured against the closing line and the final score. The record is the record, win or lose.',
                ],
                [
                    'label' => 'Improve',
                    'heading' => 'The models retrain weekly',
                    'body' => 'A market that stops beating the close loses its verdict and publishes nothing. The gate takes permission away as readily as it grants it.',
                ],
            ],
        ]);
    }

    /**
     * About, which is a page about the product and not about a company.
     *
     * The company facts a conventional About page carries - who owns
     * this, where it is registered, who works on it - are not written
     * here and will not be invented. Nothing on this site may state a
     * fact about the business that nobody has supplied, and an About
     * page is the exact place that rule is most tempting to bend.
     *
     * What it does say is true and checkable from the product: what the
     * engine does, what it refuses to do, and what the public record
     * shows. The company section is marked as outstanding rather than
     * filled with the usual sentences about passion and innovation.
     */
    public function about(PublicRecord $record): View
    {
        return view('marketing.about', [
            'record' => $this->publicRecord(),
            'proven' => VlModel::anyMarketProven(),
            'sports' => (array) config('edge.sports_enabled'),
            'principles' => [
                [
                    'heading' => 'Every published opinion is graded',
                    'body' => 'Including the losers. Nothing is removed from the record once it is published, and the record is open to anybody without an account.',
                ],
                [
                    'heading' => 'Most of the time the answer is pass',
                    'body' => 'A market has to clear a calibration gate before anything from it is published at all. On most days that leaves very little, and the product says so rather than filling the gap.',
                ],
                [
                    'heading' => 'The model shows its working',
                    'body' => 'Fair probability, the price the books are posting, and the gap between them. A number with no method behind it is a tip, and this is not a tipping service.',
                ],
                [
                    'heading' => 'Losing tools are built in',
                    'body' => 'Deposit limits that do not negotiate, loss limits, and a self-exclusion no operator here can shorten - not even the one who runs it.',
                ],
            ],
        ]);
    }

    /**
     * Features, derived from the plans rather than written beside them.
     *
     * A features page maintained by hand drifts from what the product
     * sells within a release or two, and the direction it drifts is
     * always the same: it keeps promising the thing that was removed.
     * This reads config/plans.php, so it cannot say anything the
     * checkout does not also say.
     */
    public function features(Entitlements $entitlements): View
    {
        return view('marketing.features', [
            'tiers' => collect(config('plans.tiers'))
                ->only($entitlements->sellableTiers())
                ->all(),
            'free' => (array) config('plans.tiers.free'),
        ]);
    }

    /**
     * Sports analytics: what is covered and what has been proven.
     *
     * Deliberately not a brochure. The interesting fact about this
     * engine is how much it refuses, and a coverage page that lists
     * four leagues without saying how many of their markets have
     * actually cleared the gate is describing an ambition.
     */
    public function sportsAnalytics(SyncHealth $health): View
    {
        $models = VlModel::query()
            ->orderBy('sport')
            ->orderBy('market')
            ->get(['sport', 'market', 'verdict', 'allows_picks', 'predictions', 'trained_at']);

        return view('marketing.sports-analytics', [
            'sports' => (array) config('edge.sports_enabled'),
            'models' => $models->groupBy('sport'),
            'proven' => $models->where('allows_picks', true)->count(),
            'total' => $models->count(),
            'stale' => $health->anyStale(['board']),
        ]);
    }

    public function pricing(): View
    {
        return view('marketing.pricing');
    }

    /**
     * The published track record, for the strip on the home page.
     *
     * Read from `published_picks`, which only ever contains picks
     * published before kickoff - so there is no path by which a
     * backtest could reach this figure. Every rate is null rather than
     * zero when there is no sample behind it, because the strip shows
     * whatever it is given and an unmeasured rate must not arrive
     * looking like a measured one.
     *
     * @return array<string, mixed>
     */
    private function publicRecord(): array
    {
        $summary = app(PublicRecord::class)->summary();

        return [
            'settled' => $summary['settled'],
            'record' => $summary['settled'] === 0
                ? null
                : $summary['won'].'-'.$summary['lost'].($summary['pushed'] ? '-'.$summary['pushed'] : ''),
            'units' => $summary['units'],
            'roi' => $summary['roi'],
            'clv' => $summary['clv'],
        ];
    }

    public function howItWorks(): View
    {
        return view('marketing.how-it-works');
    }

    /**
     * The daily card.
     *
     * Today deliberately shows nothing unless a market is proven. With no
     * proven market this is not an empty state to be filled - it is the
     * honest answer, and the copy says so.
     */
    public function today(SyncHealth $health): View
    {
        $picks = VlBoardEntry::query()->current()->sellablePicks()->get();

        return view('app.today', [
            'picks' => $picks,
            // Shown only when there are no picks: near misses are
            // analysis, and they are only honest while labelled a pass.
            'nearMisses' => $picks->isEmpty()
                ? VlBoardEntry::query()->current()->nearMisses()->limit(5)->get()
                : collect(),
            // The two feeds this card is built from. The schedule being
            // a day behind does not make today's board wrong.
            'delayed' => $health->customerNotice(['board', 'models']),
        ]);
    }

    /**
     * Legal pages come from one place so the footer, the signup form and
     * the terms checkbox can never drift apart from each other.
     */
    /**
     * Contact, which is an address and a map of what is already written.
     *
     * No view data: everything on it is either configuration or a route,
     * which is the point - a contact page that needed a controller to
     * tell it who to contact would have the address in two places.
     */
    public function contact(): View
    {
        return view('marketing.contact');
    }

    public function legal(string $page): View
    {
        abort_unless(in_array($page, self::LEGAL_PAGES, true), 404);

        return view("legal.{$page}");
    }
}
