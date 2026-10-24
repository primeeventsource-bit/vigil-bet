<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SyncRun;
use App\Services\EdgeLab\SyncHealth;
use Illuminate\View\View;

/**
 * Whether the product's projection of the engine is current, and what the
 * last few runs did.
 *
 * Built to make a broken projection obvious rather than to look healthy.
 * A feed that stopped being rebuilt looks exactly like a quiet night from
 * the board itself, and this page is the only place the difference is
 * visible.
 */
class SyncHealthController extends Controller
{
    public function __invoke(SyncHealth $health): View
    {
        return view('app.sync-health', [
            'feeds' => $health->all(),
            'configured' => (bool) config('edgelab.enabled'),
            'recent' => SyncRun::query()->latestFirst()->limit(25)->get(),
        ]);
    }
}
