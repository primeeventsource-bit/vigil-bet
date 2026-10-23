<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Tools\CrossBook;
use Illuminate\View\View;

/**
 * Tools: the arbitrage and middle finders.
 *
 * Both are arithmetic on prices this product already stores. Nothing is
 * fetched to build this page and nothing is written back, so §2's
 * read-only boundary and §9's no-bulk-odds rule both hold.
 *
 * It only ever sees games somebody has already opened, because that is
 * all the display mirror contains. That is the licence boundary doing its
 * job rather than a missing feature: a cross-book scanner over every game
 * in every league is precisely the bulk-odds product the licence forbids,
 * and the page says so instead of implying coverage it does not have.
 */
class ToolsController extends Controller
{
    public function __invoke(CrossBook $crossBook): View
    {
        return view('app.tools', [
            'arbitrage' => $crossBook->arbitrage(),
            'middles' => $crossBook->middles(),
        ]);
    }
}
