<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SyncRun;
use App\Services\EdgeLab\SyncHealth;
use Illuminate\View\View;

/**
 * Whether Vigil's copy of Edge Lab is current, and what the last few sync
 * attempts did.
 *
 * Built to make a broken sync obvious rather than to look healthy. A feed
 * that stopped answering looks exactly like a quiet night from the board
 * itself, and this page is the only place the difference is visible.
 */
class SyncHealthController extends Controller
{
    public function __invoke(SyncHealth $health): View
    {
        return view('app.sync-health', [
            'feeds' => $health->all(),
            'breaker' => $health->breakerState(),
            'configured' => (bool) config('edgelab.enabled'),
            'recent' => SyncRun::query()->latestFirst()->limit(25)->get(),
        ]);
    }
}
