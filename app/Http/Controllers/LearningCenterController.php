<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Learning\LearningCenter;
use Illuminate\View\View;

/**
 * The AI Learning Center.
 *
 * Answers one question the rest of the product cannot: is the engine
 * getting better? A verdict says whether a market may sell today; a
 * trajectory says whether the work is going anywhere, and right now those
 * have different answers.
 */
class LearningCenterController extends Controller
{
    public function __invoke(LearningCenter $centre): View
    {
        return view('app.learning', [
            'headline' => $centre->headline(),
            'trajectories' => $centre->trajectories(),
            'gate' => $centre->gate(),
            'board' => $centre->board(),
            'feeds' => $centre->feeds(),
            'spend' => $centre->aiSpend(),
        ]);
    }
}
