<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AlertPreference;
use App\Models\EventWatch;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\AlertNotification;
use App\Services\Entitlements\Entitlements;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Decides who hears about each alert, and records every decision.
 *
 * Every outcome is written to `alert_deliveries`, including the ones
 * where nothing was sent. That is the point: "why did I not get this"
 * has to be answerable, and a silent skip is indistinguishable from a
 * bug. So a quiet-hours skip, a cap skip, a wrong-sport skip and an
 * entitlement skip all leave a row with a reason on it.
 *
 * The unique key on (alert, user, channel) is the whole of "nobody gets
 * told twice", and it is in the database rather than in this class
 * because this class will be refactored and the database will not.
 */
class AlertDispatcher
{
    public function __construct(
        private readonly PushSender $push,
        private readonly Entitlements $entitlements,
    ) {}

    /** @return array{sent:int, skipped:int, failed:int} */
    public function run(): array
    {
        $cutoff = now()->subMinutes((int) config('alerts.max_age_minutes'));

        $alerts = Alert::query()
            /*
             * Stale alerts are dropped rather than delivered. A queue
             * that was stuck overnight must not empty itself into
             * somebody's phone in the morning, and a notification about
             * a game that has started is worse than silence.
             */
            ->where('occurred_at', '>=', $cutoff)
            ->orderBy('occurred_at')
            ->get();

        $tally = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        if ($alerts->isEmpty()) {
            return $tally;
        }

        $subscribers = AlertPreference::query()
            ->where(fn ($q) => $q->where('email_enabled', true)->orWhere('push_enabled', true))
            ->with('user')
            ->get();

        foreach ($alerts as $alert) {
            foreach ($subscribers as $preference) {
                if ($preference->user === null) {
                    continue;
                }

                foreach ($preference->channels() as $channel) {
                    $outcome = $this->deliver($alert, $preference, $channel);

                    if ($outcome !== null) {
                        $tally[$outcome]++;
                    }
                }
            }
        }

        return $tally;
    }

    /**
     * One alert, one customer, one channel.
     *
     * Returns 'sent', 'skipped', 'failed', or null when this combination
     * has already been decided - in which case nothing is recorded and
     * nothing is counted, because it is not a new event.
     */
    private function deliver(Alert $alert, AlertPreference $preference, string $channel): ?string
    {
        $user = $preference->user;

        // Already decided. Checked before anything else so a re-run is
        // cheap and silent.
        if ($this->alreadyDecided($alert, $user, $channel)) {
            return null;
        }

        if (($reason = $this->whyNot($alert, $preference, $channel)) !== null) {
            return $this->record($alert, $user, $channel, AlertDelivery::SKIPPED, $reason)
                ? 'skipped'
                : null;
        }

        $failure = $channel === 'push'
            ? $this->sendPush($user, $alert)
            : $this->sendEmail($user, $alert);

        if ($failure !== null) {
            return $this->record($alert, $user, $channel, AlertDelivery::FAILED, $failure)
                ? 'failed'
                : null;
        }

        return $this->record($alert, $user, $channel, AlertDelivery::SENT, null)
            ? 'sent'
            : null;
    }

    /**
     * Every reason this customer should not be told, in the order they
     * are cheapest to check.
     *
     * Each one leaves a row with its reason, so a customer asking why
     * they did not hear about a pick gets an answer rather than a shrug.
     */
    private function whyNot(Alert $alert, AlertPreference $preference, string $channel): ?string
    {
        $user = $preference->user;

        /*
         * Alerts are a paid feature (§4: Daily and up). Checked here as
         * well as on the page, because a customer who downgrades must
         * stop being emailed without anybody remembering to turn it off.
         */
        if ($this->entitlements->cannot($user, 'alerts')) {
            return 'not_entitled';
        }

        if (! $preference->{$alert->preferenceKey()}) {
            return 'type_not_wanted';
        }

        if (! $preference->coversSport($alert->sport)) {
            return 'sport_not_wanted';
        }

        /*
         * §9 is about responsible gambling, and a 3am notification about
         * a line move is the opposite of it. Skipped rather than
         * deferred: by morning the game may have started, and a stale
         * alert delivered late is what the age-out above prevents.
         */
        if ($preference->isQuietAt()) {
            return 'quiet_hours';
        }

        if ($alert->type === Alert::LINE_MOVED) {
            $movedBy = (int) ($alert->payload['moved_by'] ?? 0);

            if ($movedBy < (int) $preference->line_move_threshold) {
                return 'below_threshold';
            }

            // A line move on a game nobody is watching is noise, even to
            // somebody who asked for line moves.
            if (! $this->watches($user, $alert)) {
                return 'not_watching';
            }
        }

        if ($channel === 'email' && $user->email_verified_at === null) {
            // Sending to an unconfirmed address is how a domain earns a
            // spam reputation.
            return 'email_unverified';
        }

        if ($this->overCap($user, $channel)) {
            return 'daily_cap';
        }

        if ($channel === 'push' && PushSubscription::where('user_id', $user->id)->doesntExist()) {
            return 'no_push_subscription';
        }

        return null;
    }

    private function watches(User $user, Alert $alert): bool
    {
        $eventId = $alert->payload['event_id'] ?? null;

        if ($eventId === null) {
            /*
             * A line-move alert is only ever raised for a watched game,
             * so a missing id means the payload predates this check
             * rather than that nobody is watching. Allowed through: the
             * raiser is the authority on which games are watched.
             */
            return true;
        }

        return EventWatch::where('user_id', $user->id)
            ->where('edge_lab_event_id', $eventId)
            ->exists();
    }

    /**
     * Whether this customer has had their fill today.
     *
     * Counted from delivered rows, which are append-only, so the cap
     * cannot be reset by anything short of time passing.
     */
    private function overCap(User $user, string $channel): bool
    {
        $cap = (int) config('alerts.daily_cap.'.$channel, 0);

        if ($cap <= 0) {
            return false;
        }

        return AlertDelivery::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->where('status', AlertDelivery::SENT)
            ->where('delivered_at', '>=', today())
            ->count() >= $cap;
    }

    private function sendEmail(User $user, Alert $alert): ?string
    {
        try {
            $user->notify(new AlertNotification($alert));
        } catch (\Throwable $e) {
            Log::warning('Alert email failed', ['user_id' => $user->id, 'alert_id' => $alert->id]);

            return 'mail: '.$e->getMessage();
        }

        return null;
    }

    /**
     * Push, to every browser this customer has registered.
     *
     * A subscription that fails enough times is deleted. Browsers get
     * uninstalled and an endpoint that returns 410 for ever would
     * otherwise be retried on every alert until the end of time.
     */
    private function sendPush(User $user, Alert $alert): ?string
    {
        $subscriptions = PushSubscription::where('user_id', $user->id)->get();

        if ($subscriptions->isEmpty()) {
            return 'no_push_subscription';
        }

        $failures = [];

        foreach ($subscriptions as $subscription) {
            $failure = $this->push->send($subscription, [
                'title' => $this->title($alert),
                'body' => $this->body($alert),
                'url' => $alert->payload['url'] ?? route('today'),
                'tag' => $alert->type.':'.$alert->subject_id,
            ]);

            if ($failure === null) {
                $subscription->update(['last_used_at' => now(), 'failures' => 0]);

                continue;
            }

            $failures[] = $failure;

            if ($this->push->isGone($failure)) {
                $subscription->delete();

                continue;
            }

            $subscription->increment('failures');

            if ($subscription->failures >= PushSubscription::MAX_FAILURES) {
                $subscription->delete();
            }
        }

        // Delivered to at least one browser counts as delivered: a
        // customer with a laptop and a phone has been told.
        return count($failures) === $subscriptions->count()
            ? implode('; ', array_slice($failures, 0, 2))
            : null;
    }

    public function title(Alert $alert): string
    {
        $brand = (string) config('brand.name');

        return match ($alert->type) {
            Alert::PICK_POSTED => $brand.' pick: '.($alert->payload['selection'] ?? 'new pick'),
            Alert::LINE_MOVED => 'Line moved: '.($alert->payload['matchup'] ?? 'a game you watch'),
            default => $brand,
        };
    }

    /**
     * The message.
     *
     * Every figure in it comes from the payload recorded when the alert
     * was raised. Nothing is looked up now: a notification built from a
     * live read could describe a price that has since moved, which is
     * the one thing a price alert must never do.
     */
    public function body(Alert $alert): string
    {
        $p = $alert->payload;

        return match ($alert->type) {
            Alert::PICK_POSTED => trim(sprintf(
                '%s at %s%s. %s%s',
                $p['selection'] ?? 'A selection',
                $p['price'] ?? 'an unstated price',
                isset($p['book']) ? ' with '.$p['book'] : '',
                isset($p['model_probability']) ? 'Model says '.$p['model_probability'].'. ' : '',
                isset($p['matchup']) ? $p['matchup'].'.' : '',
            )),

            Alert::LINE_MOVED => trim(sprintf(
                '%s %s from %s to %s at %s.',
                $p['side'] ?? 'A price',
                $p['direction'] ?? 'moved',
                $p['from'] ?? '?',
                $p['to'] ?? '?',
                $p['book'] ?? 'a book',
            )),

            default => '',
        };
    }

    private function alreadyDecided(Alert $alert, User $user, string $channel): bool
    {
        return AlertDelivery::query()
            ->where('alert_id', $alert->id)
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->exists();
    }

    /** False when the unique key refused it: somebody else got there first. */
    private function record(Alert $alert, User $user, string $channel, string $status, ?string $reason): bool
    {
        try {
            AlertDelivery::create([
                'alert_id' => $alert->id,
                'user_id' => $user->id,
                'channel' => $channel,
                'status' => $status,
                'reason' => $reason,
                'delivered_at' => now(),
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'unique')) {
                throw $e;
            }

            return false;
        }

        return true;
    }
}
