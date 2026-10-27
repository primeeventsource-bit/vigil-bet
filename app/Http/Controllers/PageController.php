<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Opinion;
use App\Models\VlBoardEntry;
use App\Services\EdgeLab\SyncHealth;
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
        $ticker = $markets->upcoming(null, 36)
            ->filter(static fn (array $game): bool => ($game['markets']['h2h'] ?? []) !== [])
            ->take(12)
            ->values();

        /*
         * The three steps live here rather than in the template because
         * they are copy, and copy that sits in a Blade loop is copy nobody
         * can find when it needs changing.
         */
        return view('marketing.home', [
            'record' => $this->publicRecord(),

            'ticker' => $ticker,
            'tickerPricedAt' => $ticker->isEmpty() ? null : $markets->lastPricedAt(),

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
            'steps' => [
                [
                    'heading' => 'We read the whole market',
                    'body' => 'Every tracked book, every couple of minutes near kickoff, so the price on the screen is the price that is there.',
                ],
                [
                    'heading' => 'We strip out the vig',
                    'body' => "A fair probability from the market, and the model's probability beside it. The gap is the edge.",
                ],
                [
                    'heading' => 'We say pick or pass',
                    'body' => 'Most of the time it is pass, and we say so as plainly as we say pick.',
                ],
            ],
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
    public function legal(string $page): View
    {
        abort_unless(in_array($page, ['terms', 'privacy', 'responsible-gambling', 'disclaimer'], true), 404);

        return view("legal.{$page}");
    }
}
