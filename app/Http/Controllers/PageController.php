<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VlBoardEntry;
use App\Services\EdgeLab\SyncHealth;
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
    public function home(): View
    {
        /*
         * The three steps live here rather than in the template because
         * they are copy, and copy that sits in a Blade loop is copy nobody
         * can find when it needs changing.
         */
        return view('marketing.home', [
            'record' => $this->publicRecord(),
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
     * The published track record.
     *
     * Returns zeros rather than figures because nothing has been
     * published: `published_picks` arrives at step 9. Every caller checks
     * `settled` before showing a rate, and the one that does not would be
     * the bug - so there is nothing here that could be mistaken for a
     * result.
     *
     * @return array<string, mixed>
     */
    private function publicRecord(): array
    {
        return [
            'settled' => 0,
            'record' => null,
            'units' => null,
            'roi' => null,
            'clv' => null,
        ];
    }

    public function howItWorks(): View
    {
        return view('marketing.how-it-works');
    }

    public function record(): View
    {
        return view('marketing.record', ['record' => $this->publicRecord()]);
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
