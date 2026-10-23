<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AlertDelivery;
use App\Models\AlertPreference;
use App\Models\EventWatch;
use App\Models\PushSubscription;
use App\Models\VlEvent;
use App\Services\Alerts\PushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Alerts: what a customer is told, and how.
 *
 * Both channels start off. An account that began emailing somebody
 * because they signed up would be sending unsolicited mail whatever the
 * footer said - and the page shows the delivery log, including the
 * skips, so "why did I not get that" has an answer rather than a shrug.
 */
class AlertController extends Controller
{
    public function index(Request $request, PushSender $push): View
    {
        $user = $request->user();

        return view('app.alerts', [
            'preference' => AlertPreference::firstOrCreate(['user_id' => $user->id]),
            'pushConfigured' => $push->configured(),
            'vapidPublicKey' => config('alerts.vapid.public_key'),
            'subscriptions' => PushSubscription::where('user_id', $user->id)->get(),
            'watches' => $this->watchedGames($user->id),
            // Games that can be watched: only ones the mirror holds, and
            // only ones that have not started.
            'watchable' => VlEvent::query()->current()->upcoming()
                ->orderBy('starts_at')
                ->limit(30)
                ->get(),
            // The log, skips included. This is the part that makes the
            // page honest rather than a set of switches.
            'deliveries' => AlertDelivery::query()
                ->where('user_id', $user->id)
                ->with('alert')
                ->orderByDesc('delivered_at')
                ->orderByDesc('id')
                ->limit(25)
                ->get(),
            'caps' => config('alerts.daily_cap'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email_enabled' => ['nullable', 'boolean'],
            'push_enabled' => ['nullable', 'boolean'],
            'on_new_pick' => ['nullable', 'boolean'],
            'on_line_move' => ['nullable', 'boolean'],
            'sports' => ['nullable', 'array'],
            'sports.*' => ['string', 'max:20'],
            'line_move_threshold' => ['required', 'integer', 'min:5', 'max:200'],
            'quiet_from_hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'quiet_to_hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'timezone' => ['required', 'timezone'],
        ]);

        AlertPreference::updateOrCreate(['user_id' => $request->user()->id], [
            'email_enabled' => (bool) ($data['email_enabled'] ?? false),
            'push_enabled' => (bool) ($data['push_enabled'] ?? false),
            'on_new_pick' => (bool) ($data['on_new_pick'] ?? false),
            'on_line_move' => (bool) ($data['on_line_move'] ?? false),
            'sports' => $data['sports'] ?? [],
            'line_move_threshold' => (int) $data['line_move_threshold'],
            // Both or neither: half a quiet window is not a window.
            'quiet_from_hour' => ($data['quiet_from_hour'] ?? null) === null
                || ($data['quiet_to_hour'] ?? null) === null
                    ? null
                    : (int) $data['quiet_from_hour'],
            'quiet_to_hour' => ($data['quiet_from_hour'] ?? null) === null
                || ($data['quiet_to_hour'] ?? null) === null
                    ? null
                    : (int) $data['quiet_to_hour'],
            'timezone' => $data['timezone'],
        ]);

        return back()->with('status', 'Saved.');
    }

    /**
     * Register a browser for push.
     *
     * The endpoint and keys come from the browser's own Push API. They
     * are not credentials of ours and are useless to anybody without the
     * VAPID private key, which lives in the environment.
     *
     * Keyed on a hash of the endpoint, so the same browser
     * re-subscribing replaces its row rather than accumulating them.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000', 'url'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashFor($data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                // A re-subscription is a working browser, so the failure
                // count starts again.
                'failures' => 0,
            ],
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Stop pushing to this browser.
     *
     * Deleted rather than flagged. A customer who turns push off has
     * withdrawn consent, and a row kept "in case they come back" is a
     * row somebody will eventually send to.
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
        ]);

        PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashFor($data['endpoint']))
            ->delete();

        return response()->json(['ok' => true]);
    }

    /** Watch a game, for line moves. */
    public function watch(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'edge_lab_event_id' => ['required', 'integer'],
        ]);

        EventWatch::firstOrCreate(
            [
                'user_id' => $request->user()->id,
                'edge_lab_event_id' => $data['edge_lab_event_id'],
            ],
            ['created_at' => now()],
        );

        return back();
    }

    public function unwatch(Request $request): RedirectResponse
    {
        EventWatch::query()
            ->where('user_id', $request->user()->id)
            ->where('edge_lab_event_id', $request->integer('edge_lab_event_id'))
            ->delete();

        return back();
    }

    /**
     * The games this customer watches, with their names.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function watchedGames(int $userId): Collection
    {
        $ids = EventWatch::where('user_id', $userId)->pluck('edge_lab_event_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        $events = VlEvent::query()->current()->whereIn('edge_lab_id', $ids)->get()->keyBy('edge_lab_id');

        return $ids->map(fn (int $id) => [
            'edge_lab_event_id' => $id,
            // A watched game whose event has aged out of the mirror is
            // still listed, so it can be removed rather than becoming an
            // invisible row that quietly stops working.
            'matchup' => $events->get($id)?->matchup() ?? 'Game '.$id,
            'starts_at' => $events->get($id)?->starts_at,
        ])->values();
    }
}
