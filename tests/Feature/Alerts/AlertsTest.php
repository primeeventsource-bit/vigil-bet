<?php

declare(strict_types=1);

use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AlertPreference;
use App\Models\EventWatch;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\AlertNotification;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Alerts\AlertRaiser;
use App\Services\Alerts\PushSender;
use App\Services\Record\PickPublisher;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

/** A sender that records rather than sends. */
function fakePush(?string $failure = null, bool $configured = true): object
{
    $fake = new class($failure, $configured) implements PushSender
    {
        /** @var array<int, array<string, mixed>> */
        public array $sent = [];

        public function __construct(private ?string $failure, private bool $isConfigured) {}

        public function configured(): bool
        {
            return $this->isConfigured;
        }

        public function send(PushSubscription $subscription, array $payload): ?string
        {
            $this->sent[] = ['endpoint' => $subscription->endpoint] + $payload;

            return $this->failure;
        }

        public function isGone(string $failure): bool
        {
            return str_contains($failure, '410');
        }
    };

    app()->instance(PushSender::class, $fake);

    return $fake;
}

function subscriber(array $preferences = [], ?string $tier = 'daily'): User
{
    $user = betUser($tier);

    AlertPreference::updateOrCreate(['user_id' => $user->id], array_merge([
        'email_enabled' => true,
        'push_enabled' => false,
        'on_new_pick' => true,
        'on_line_move' => false,
        'sports' => [],
        'line_move_threshold' => 15,
        'timezone' => 'UTC',
    ], $preferences));

    return $user->refresh();
}

function pickAlert(array $payload = [], array $attributes = []): Alert
{
    static $n = 0;
    $n++;

    return Alert::create(array_merge([
        'type' => Alert::PICK_POSTED,
        'subject_type' => 'published_pick',
        'subject_id' => $n,
        'sport' => 'nfl',
        'market' => 'h2h',
        'payload' => array_merge([
            'selection' => 'Chiefs',
            'matchup' => 'Bills at Chiefs',
            'price' => '-110',
            'book' => 'DraftKings',
            'model_probability' => '58%',
            'url' => 'https://example.test/record/abc',
        ], $payload),
        'occurred_at' => now(),
        'created_at' => now(),
    ], $attributes));
}

/* ----------------------------------------------------------------------
 | Delivery
 ---------------------------------------------------------------------- */

it('emails a subscriber about a new pick and records the delivery', function () {
    $user = subscriber();
    pickAlert();

    $tally = app(AlertDispatcher::class)->run();

    Notification::assertSentTo($user, AlertNotification::class);

    expect($tally['sent'])->toBe(1)
        ->and(AlertDelivery::sole()->status)->toBe(AlertDelivery::SENT);
});

it('never tells the same person about the same alert twice', function () {
    $user = subscriber();
    pickAlert();

    app(AlertDispatcher::class)->run();
    app(AlertDispatcher::class)->run();
    app(AlertDispatcher::class)->run();

    // The failure here is not a missing notification. It is the same
    // push eleven times, which gets an application uninstalled.
    Notification::assertSentToTimes($user, AlertNotification::class, 1);

    expect(AlertDelivery::count())->toBe(1);
});

it('raises one alert per pick however often the raiser runs', function () {
    publishableBoard();
    app(PickPublisher::class)->run();

    app(AlertRaiser::class)->run();
    app(AlertRaiser::class)->run();
    app(AlertRaiser::class)->run();

    expect(Alert::where('type', Alert::PICK_POSTED)->count())->toBe(1);
});

it('sends nothing to somebody who has not turned a channel on', function () {
    subscriber(['email_enabled' => false, 'push_enabled' => false]);
    pickAlert();

    $tally = app(AlertDispatcher::class)->run();

    // Both channels start off. An account that began emailing somebody
    // because they signed up would be sending unsolicited mail.
    expect($tally)->toBe(['sent' => 0, 'skipped' => 0, 'failed' => 0])
        ->and(AlertDelivery::count())->toBe(0);
});

/* ----------------------------------------------------------------------
 | Every reason not to send, each recorded with its reason
 ---------------------------------------------------------------------- */

it('records why it skipped, rather than skipping silently', function (array $preferences, string $reason) {
    subscriber($preferences);
    pickAlert();

    app(AlertDispatcher::class)->run();

    // "Why did I not get that one" has to be answerable. A silent skip
    // is indistinguishable from a bug.
    $delivery = AlertDelivery::sole();

    expect($delivery->status)->toBe(AlertDelivery::SKIPPED)
        ->and($delivery->reason)->toBe($reason);
})->with([
    'type turned off' => [['on_new_pick' => false], 'type_not_wanted'],
    'another sport' => [['sports' => ['nba']], 'sport_not_wanted'],
]);

it('stops emailing a customer who has dropped to the free plan', function () {
    $user = subscriber([], tier: null);
    pickAlert();

    app(AlertDispatcher::class)->run();

    // Checked at delivery as well as on the page, so a downgrade stops
    // the mail without anybody remembering to turn it off.
    expect(AlertDelivery::sole()->reason)->toBe('not_entitled');
    Notification::assertNothingSent();
});

it('does not email an unconfirmed address', function () {
    $user = subscriber();
    $user->forceFill(['email_verified_at' => null])->save();

    pickAlert();

    app(AlertDispatcher::class)->run();

    // Sending to an unconfirmed address is how a domain earns a spam
    // reputation.
    expect(AlertDelivery::sole()->reason)->toBe('email_unverified');
});

it('says nothing during the hours a customer asked to be left alone', function () {
    // Midnight UTC, inside a 22:00-07:00 window.
    $this->travelTo(now()->startOfDay()->addHours(2));

    subscriber(['quiet_from_hour' => 22, 'quiet_to_hour' => 7]);
    pickAlert();

    app(AlertDispatcher::class)->run();

    // A three in the morning notification about a line move is the
    // opposite of §9.
    expect(AlertDelivery::sole()->reason)->toBe('quiet_hours');
    Notification::assertNothingSent();
});

it('reads a quiet window that crosses midnight as one window', function () {
    $preference = new AlertPreference([
        'quiet_from_hour' => 22, 'quiet_to_hour' => 7, 'timezone' => 'UTC',
    ]);

    expect($preference->isQuietAt(now()->startOfDay()->addHours(23)))->toBeTrue()
        ->and($preference->isQuietAt(now()->startOfDay()->addHours(3)))->toBeTrue()
        ->and($preference->isQuietAt(now()->startOfDay()->addHours(12)))->toBeFalse();
});

it('respects a quiet window in the customers own timezone', function () {
    // 02:00 in New York is 06:00 UTC. A window stated in local hours
    // that fired on UTC would be useless to everybody outside it.
    $this->travelTo(now()->startOfDay()->addHours(6));

    subscriber(['quiet_from_hour' => 1, 'quiet_to_hour' => 6, 'timezone' => 'America/New_York']);
    pickAlert();

    app(AlertDispatcher::class)->run();

    expect(AlertDelivery::sole()->reason)->toBe('quiet_hours');
});

it('stops once a customer has had their fill for the day', function () {
    config()->set('alerts.daily_cap.email', 2);

    $user = subscriber();

    pickAlert();
    pickAlert();
    pickAlert();

    app(AlertDispatcher::class)->run();

    Notification::assertSentToTimes($user, AlertNotification::class, 2);

    expect(AlertDelivery::where('status', AlertDelivery::SENT)->count())->toBe(2)
        ->and(AlertDelivery::where('reason', 'daily_cap')->count())->toBe(1);
});

it('drops an alert that has gone stale rather than delivering it late', function () {
    config()->set('alerts.max_age_minutes', 60);

    $user = subscriber();
    pickAlert(attributes: ['occurred_at' => now()->subHours(5)]);

    app(AlertDispatcher::class)->run();

    // A queue stuck overnight must not empty itself into somebody's
    // phone in the morning.
    Notification::assertNothingSent();
    expect(AlertDelivery::count())->toBe(0);
});

/* ----------------------------------------------------------------------
 | Web push
 ---------------------------------------------------------------------- */

function pushSub(User $user, string $endpoint = 'https://push.test/abc'): PushSubscription
{
    return PushSubscription::create([
        'user_id' => $user->id,
        'endpoint' => $endpoint,
        'endpoint_hash' => PushSubscription::hashFor($endpoint),
        'p256dh' => 'key',
        'auth' => 'auth',
    ]);
}

it('pushes to a registered browser with the stored figures', function () {
    $sender = fakePush();

    $user = subscriber(['email_enabled' => false, 'push_enabled' => true]);
    pushSub($user);
    pickAlert();

    app(AlertDispatcher::class)->run();

    expect($sender->sent)->toHaveCount(1)
        ->and($sender->sent[0]['body'])->toContain('-110')
        // Every figure comes from the payload stored when the alert was
        // raised. A push built from a live read could quote a price that
        // has since moved.
        ->and($sender->sent[0]['body'])->toContain('58%')
        ->and($sender->sent[0]['tag'])->toContain(Alert::PICK_POSTED);
});

it('skips push when no browser is registered', function () {
    fakePush();

    subscriber(['email_enabled' => false, 'push_enabled' => true]);
    pickAlert();

    app(AlertDispatcher::class)->run();

    expect(AlertDelivery::sole()->reason)->toBe('no_push_subscription');
});

it('drops a subscription whose browser is gone for good', function () {
    fakePush(failure: 'http_410: Gone');

    $user = subscriber(['email_enabled' => false, 'push_enabled' => true]);
    pushSub($user);
    pickAlert();

    app(AlertDispatcher::class)->run();

    // A browser that has been uninstalled returns 410 for ever, and
    // retrying it on every alert until the end of time is pointless.
    expect(PushSubscription::count())->toBe(0)
        ->and(AlertDelivery::sole()->status)->toBe(AlertDelivery::FAILED);
});

it('keeps a subscription that failed once but might recover', function () {
    fakePush(failure: 'http_500: Server error');

    $user = subscriber(['email_enabled' => false, 'push_enabled' => true]);
    pushSub($user);
    pickAlert();

    app(AlertDispatcher::class)->run();

    // A 500 is worth another go; a 410 is not.
    expect(PushSubscription::count())->toBe(1)
        ->and(PushSubscription::sole()->failures)->toBe(1);
});

it('registers a browser and replaces its row on re-subscribe', function () {
    $user = subscriber();

    $this->actingAs($user)->postJson(route('alerts.subscribe'), [
        'endpoint' => 'https://push.test/same',
        'keys' => ['p256dh' => 'k1', 'auth' => 'a1'],
    ])->assertOk();

    $this->actingAs($user)->postJson(route('alerts.subscribe'), [
        'endpoint' => 'https://push.test/same',
        'keys' => ['p256dh' => 'k2', 'auth' => 'a2'],
    ])->assertOk();

    // The same browser re-subscribing replaces rather than accumulates.
    expect(PushSubscription::count())->toBe(1)
        ->and(PushSubscription::sole()->p256dh)->toBe('k2');
});

it('deletes a subscription when a customer turns push off for a browser', function () {
    $user = subscriber();
    pushSub($user, 'https://push.test/gone');

    $this->actingAs($user)
        ->deleteJson(route('alerts.unsubscribe'), ['endpoint' => 'https://push.test/gone'])
        ->assertOk();

    // Deleted rather than flagged: a row kept "in case they come back"
    // is a row somebody will eventually send to.
    expect(PushSubscription::count())->toBe(0);
});

it('says push is unavailable rather than offering a switch that does nothing', function () {
    fakePush(configured: false);

    $this->actingAs(subscriber())->get(route('alerts'))
        ->assertOk()
        ->assertSee('Not available yet')
        ->assertSee('no push keys are configured');
});

/* ----------------------------------------------------------------------
 | Line moves
 ---------------------------------------------------------------------- */

it('only raises a line move on a game somebody watches', function () {
    crossGame(900);

    crossQuote(['edge_lab_event_id' => 900, 'price_american' => -110, 'quoted_at' => now()->subHours(3)]);
    crossQuote(['edge_lab_event_id' => 900, 'price_american' => -140, 'quoted_at' => now()->subHour()]);

    expect(app(AlertRaiser::class)->lineMoves())->toBe(0);

    EventWatch::create([
        'user_id' => betUser()->id, 'edge_lab_event_id' => 900, 'created_at' => now(),
    ]);

    expect(app(AlertRaiser::class)->lineMoves())->toBe(1);

    $alert = Alert::where('type', Alert::LINE_MOVED)->sole();

    expect($alert->payload['moved_by'])->toBe(30)
        ->and($alert->payload['direction'])->toBe('shortened');
});

it('ignores a move too small to be worth saying', function () {
    crossGame(900);
    EventWatch::create(['user_id' => betUser()->id, 'edge_lab_event_id' => 900, 'created_at' => now()]);

    crossQuote(['edge_lab_event_id' => 900, 'price_american' => -110, 'quoted_at' => now()->subHours(3)]);
    crossQuote(['edge_lab_event_id' => 900, 'price_american' => -112, 'quoted_at' => now()->subHour()]);

    // Noise even for a customer who asked for everything.
    expect(app(AlertRaiser::class)->lineMoves())->toBe(0);
});

it('applies each customers own threshold at delivery', function () {
    crossGame(900);

    $fussy = subscriber(['on_new_pick' => false, 'on_line_move' => true, 'line_move_threshold' => 50]);
    EventWatch::create(['user_id' => $fussy->id, 'edge_lab_event_id' => 900, 'created_at' => now()]);

    crossQuote(['edge_lab_event_id' => 900, 'price_american' => -110, 'quoted_at' => now()->subHours(3)]);
    crossQuote(['edge_lab_event_id' => 900, 'price_american' => -140, 'quoted_at' => now()->subHour()]);

    app(AlertRaiser::class)->lineMoves();
    app(AlertDispatcher::class)->run();

    // Raised once for everybody, filtered per customer. Scanning per
    // customer would ask the same question once per subscriber.
    expect(AlertDelivery::sole()->reason)->toBe('below_threshold');
});

it('never rewrites an alert or a delivery', function () {
    subscriber();
    $alert = pickAlert();

    app(AlertDispatcher::class)->run();

    expect(fn () => $alert->update(['type' => Alert::LINE_MOVED]))
        ->toThrow(RuntimeException::class, 'append-only');

    expect(fn () => AlertDelivery::sole()->update(['status' => 'skipped', 'reason' => 'rewritten']))
        ->toThrow(RuntimeException::class, 'append-only');
});

it('keeps alerts behind a paid plan', function () {
    $this->actingAs(betUser(null))->get(route('alerts'))
        ->assertRedirect(route('upgrade', ['feature' => 'alerts']));

    $this->actingAs(betUser('daily'))->get(route('alerts'))->assertOk();
});

it('shows the skips on the page, not just the sends', function () {
    $user = subscriber(['sports' => ['nba']]);
    pickAlert();

    app(AlertDispatcher::class)->run();

    $this->actingAs($user)->get(route('alerts'))
        ->assertOk()
        ->assertSee('Skipped')
        ->assertSee('sport not wanted');
});
