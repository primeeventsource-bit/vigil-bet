<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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

    public function howItWorks(): View
    {
        return view('marketing.how-it-works');
    }

    public function record(): View
    {
        return view('marketing.record');
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
