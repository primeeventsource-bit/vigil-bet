<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AdminActionController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminManagementController;
// Aliased: there is a customer-facing TrialController too, and two
// classes with one name in a routes file is a mistake waiting to be
// made at the worst moment.
use App\Http\Controllers\Admin\TrialController as AdminTrialController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\BankrollController;
use App\Http\Controllers\BetController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GamesController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LearningCenterController;
use App\Http\Controllers\ModelLabController;
use App\Http\Controllers\OddsComparisonController;
use App\Http\Controllers\OpinionRecordController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ParlayController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\PlayerIqController;
use App\Http\Controllers\PropProjectionController;
use App\Http\Controllers\PropsController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\SafetyController;
use App\Http\Controllers\SavedPickController;
use App\Http\Controllers\SyncHealthController;
use App\Http\Controllers\ToolsController;
use App\Http\Controllers\TrialController;
use App\Http\Controllers\UpgradeController;
use App\Http\Controllers\WebhookController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Marketing and legal. Public, indexable, no Livewire.
|--------------------------------------------------------------------------
*/

/*
 * Health, ahead of everything else and outside every middleware group
 * that could keep it from answering. Laravel's `/up` already reports that
 * the framework booted; this reports whether the database it needs is
 * there, which is the failure that otherwise goes unnoticed.
 */
Route::get('health', HealthController::class)->name('health');

Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/pricing', [PageController::class, 'pricing'])->name('pricing');
Route::get('/how-it-works', [PageController::class, 'howItWorks'])->name('how-it-works');
/*
 * The public track record. §7: the single most important trust asset,
 * and the only page on this site a sceptic will take seriously - so it
 * is public, indexable, and shows the losers.
 */
Route::get('/record', [RecordController::class, 'index'])->name('record');
Route::get('/record/{pick}', [RecordController::class, 'show'])->name('record.pick');

/*
 * How the published opinions have done.
 *
 * Its own page, and never merged into /record. They are different
 * claims: a pick says this market has beaten the closing line, an
 * opinion says only that the stated probability was close to true.
 * Summing them would destroy the stronger one.
 *
 * Public, for the same reason /record is. An opinion published without
 * its outcome shown is a claim nobody can check.
 */
Route::get('/opinions', OpinionRecordController::class)->name('opinions');

/*
 * The pages a stranger reads before deciding whether this is a tip
 * service. Public and indexable, all three built from real figures
 * rather than from adjectives.
 */
Route::get('/about', [PageController::class, 'about'])->name('about');
Route::get('/features', [PageController::class, 'features'])->name('features');
Route::get('/sports-analytics', [PageController::class, 'sportsAnalytics'])->name('sports-analytics');

Route::get('/legal/{page}', [PageController::class, 'legal'])
    ->whereIn('page', PageController::LEGAL_PAGES)
    ->name('legal.page');

/*
 * Named individually as well, so a template never builds a legal URL by
 * string concatenation and no typo can produce a 404 in a footer.
 *
 * Both this and the constraint above read PageController::LEGAL_PAGES.
 * The list used to be written out three times, and adding a page to two
 * of the three produced a 404 that looked like a missing view.
 */
foreach (PageController::LEGAL_PAGES as $page) {
    Route::get("/legal/{$page}", [PageController::class, 'legal'])
        ->defaults('page', $page)
        ->name("legal.{$page}");
}

/*
|--------------------------------------------------------------------------
| Signing in and out
|--------------------------------------------------------------------------
|
| The age gate lives inside registration rather than beside it: see
| RegisterController. Throttled, because these are the endpoints a stranger
| gets to call as often as they like.
|
*/

Route::middleware('guest')->group(function (): void {
    Route::get('register', [RegisterController::class, 'show'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:10,1');

    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:10,1');

    Route::get('two-factor', [LoginController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('two-factor', [LoginController::class, 'verify'])->middleware('throttle:10,1');

    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])
        ->middleware('throttle:6,1')->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:6,1')->name('password.update');
});

Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

/*
 * Where a self-excluded customer lands. Public, because the middleware
 * signs them out before sending them here - and it offers no way back
 * in, which is the whole point of §9's promise.
 */
Route::get('excluded', [SafetyController::class, 'excluded'])->name('excluded');

/*
|--------------------------------------------------------------------------
| Local only: sign in as the demo user
|--------------------------------------------------------------------------
|
| A login bypass, which is why it is fenced twice. The route is only
| registered when the application is running locally, and it aborts on
| the same condition when it runs - a single guard is one refactor away
| from being deleted by somebody who does not know what it was for.
|
| It exists so a design review can look at the dashboard without typing
| a password into a headless browser, and it goes no further than the
| user `vigil:demo` creates. There is a test asserting the route does not
| exist outside local.
|
*/
if (app()->environment('local')) {
    Route::get('__demo-login', function () {
        abort_unless(app()->environment('local'), 404);

        $user = User::where('email', 'demo@example.com')->first();

        abort_if($user === null, 404, 'Run php artisan vigil:demo first.');

        Auth::login($user);

        return redirect()->route('today');
    })->name('demo.login');
}

/*
|--------------------------------------------------------------------------
| The app. Signed in, email confirmed, noindex.
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function (): void {
    Route::get('verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('verify-email/send', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')->name('verification.send');

    Route::middleware('verified')->group(function (): void {
        Route::get('today', DashboardController::class)->name('today');
        Route::get('account', AccountController::class)->name('account');

        /*
         * The rest of the sidebar. Each is a real route with an honest
         * placeholder naming the step that fills it - a nav item that
         * 404s is worse than one that says "not built yet", and a nav
         * item quietly missing is worse than both.
         */
        /*
         * Where a customer is told what a plan would give them. Never
         * gated itself - sending somebody who cannot afford a feature to
         * a page they also cannot see would be its own kind of joke.
         */
        Route::get('upgrade', UpgradeController::class)->name('upgrade');

        /*
         * The trial. Inside `verified`, so the spec's "registration and
         * a confirmed address before the trial" is enforced by the same
         * middleware guarding the rest of the product rather than by a
         * check in a controller somebody could forget to copy.
         *
         * Activation is an explicit act rather than a side effect of
         * confirming an address: somebody who signs up at midnight and
         * comes back on Saturday should get their day on Saturday.
         */
        Route::get('trial', [TrialController::class, 'show'])->name('trial');

        Route::post('trial', [TrialController::class, 'activate'])
            ->middleware('throttle:10,1')->name('trial.activate');

        /*
         * Billing. Throttled because each one is a call to a payment
         * processor, and cancelling is a single POST with no
         * confirmation funnel in front of it - §4 asks for one click
         * and no dark patterns, and a "are you sure?" page is one.
         */
        Route::middleware('throttle:20,1')->group(function (): void {
            Route::post('billing/subscribe', [BillingController::class, 'subscribe'])->name('billing.subscribe');
            Route::post('billing/plan', [BillingController::class, 'changePlan'])->name('billing.plan');
            Route::post('billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
            Route::post('billing/resume', [BillingController::class, 'resume'])->name('billing.resume');
            Route::post('billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
        });

        /*
         * Feature-gated routes.
         *
         * The nav hides what a customer cannot use, and this is the part
         * that holds when they edit the URL. §13 is tested against it: a
         * Free user must not reach Pro data through any route, action or
         * JSON response.
         */
        Route::middleware('entitled:board.full')->group(function (): void {
            /*
             * The full value board: every candidate the engine judged,
             * filterable by sport, date, market, book and whether it was
             * a pick or a pass.
             *
             * Two names for one page. The design calls the nav item
             * "Live Odds" and the spec calls the page "Board", and a
             * customer who clicks either should land on the board rather
             * than on two slightly different versions of it.
             */
            Route::get('live-odds', BoardController::class)->name('live-odds');
            Route::get('picks', BoardController::class)->name('picks');
        });

        /*
         * Parlays. The grader is ungated - §4 gives the free plan three
         * a day - and the daily cap is counted in the controller.
         */
        Route::middleware('entitled:parlay.grade')->group(function (): void {
            /*
             * Today's games: the betting surface. Separate from the
             * board, which records what the engine decided and refuses
             * everything it has not proven - the restriction that left
             * the builder with nothing to click.
             */
            Route::get('games', [GamesController::class, 'index'])->name('games');

            Route::get('parlays', [ParlayController::class, 'index'])->name('parlays');
            Route::post('parlays/legs', [ParlayController::class, 'add'])->name('parlays.add');
            Route::delete('parlays/legs', [ParlayController::class, 'remove'])->name('parlays.remove');
            Route::delete('parlays', [ParlayController::class, 'clear'])->name('parlays.clear');
            Route::post('parlays/grade', [ParlayController::class, 'grade'])
                ->middleware('throttle:30,1')->name('parlays.grade');

            /*
             * Saving and tracking. Throttled like grading, because each
             * save writes a parlay, its legs and an audit row.
             */
            Route::post('parlays/save', [ParlayController::class, 'save'])
                ->middleware('throttle:30,1')->name('parlays.save');
        });

        /*
         * Reading parlays you already saved, deliberately outside the
         * group above.
         *
         * It used to need `parlay.grade`, which meant a trial ending
         * took away the work somebody did during it. The spec asks that
         * saved parlays be preserved when a trial expires, and data
         * nobody can open is not preserved in any sense that matters to
         * the person who entered it.
         *
         * Building and grading stay gated. Reading your own work back
         * is not a feature to sell.
         */
        Route::get('my-parlays', [ParlayController::class, 'mine'])->name('my-parlays');

        /*
         * Results and performance: a customer's own record beside the
         * engine's. Both of these used to render app/placeholder -
         * "Not built yet, Step 8" and "Not built yet, Step 9" - while
         * BetRecord had been computing every figure on them since bets
         * were first tracked.
         *
         * Ungated. The figures are the customer's own, and charging
         * somebody to see what they themselves entered would be an odd
         * thing to sell. The one line that is gated is closing line
         * value, which the tile locks on its own.
         */
        Route::get('performance', PerformanceController::class)->name('performance');
        Route::get('analytics', PerformanceController::class)->name('analytics');

        /*
         * Props and Tools. Both Pro, both read-only, and both limited
         * to games somebody has already opened - which is the licence
         * boundary doing its job rather than a missing feature.
         */
        Route::get('props', PropsController::class)
            ->middleware('entitled:props')->name('props');

        Route::get('tools', ToolsController::class)
            ->middleware('entitled:tools.crossbook')->name('tools');

        // Model Lab: the transparency page. Pro, per §4.
        Route::get('models', ModelLabController::class)
            ->middleware('entitled:modellab')->name('models');

        // Kept as a name so nothing linking to it breaks; it is the
        // same page, which is what "Reports" always meant.
        Route::get('reports', PerformanceController::class)->name('reports');

        /*
         * Player IQ. Elite, and one of the two grants that let Elite
         * claim anything Pro did not already have - the ratings have
         * been computed nightly since launch and displayed nowhere.
         */
        /*
         * Projected props. Elite's other grant, and a separate page
         * from Props on purpose - that controller states an invariant
         * that no probability or edge may ever appear on it, because
         * its quotes come from a mirror the model pipeline is
         * structurally forbidden to read. This is the other pipeline.
         */
        Route::get('prop-projections', PropProjectionController::class)
            ->middleware('entitled:props.projected')->name('prop-projections');

        /*
         * My Picks: opinions a customer kept. Ungated, because a
         * shortlist of things the product already showed them is not
         * something to sell, and gating it would mean a downgrade
         * silently emptying a list somebody built.
         */
        Route::get('my-picks', [SavedPickController::class, 'index'])->name('my-picks');

        Route::middleware('throttle:60,1')->group(function (): void {
            Route::post('my-picks/{opinion}', [SavedPickController::class, 'store'])->name('my-picks.save');
            Route::delete('my-picks/{opinion}', [SavedPickController::class, 'destroy'])->name('my-picks.remove');
        });

        Route::get('player-iq', [PlayerIqController::class, 'index'])
            ->middleware('entitled:playeriq')->name('player-iq');

        Route::get('player-iq/{sport}/{player}', [PlayerIqController::class, 'show'])
            ->middleware('entitled:playeriq')->name('player-iq.show');

        /*
         * Odds comparison: every book's price for one game, side by
         * side. Distinct from Today's Games, which shows the best of N
         * and hides who is offering it.
         */
        Route::get('odds', [OddsComparisonController::class, 'index'])
            ->middleware('entitled:lineshopping')->name('odds');

        Route::get('odds/{event}', [OddsComparisonController::class, 'show'])
            ->middleware('entitled:lineshopping')->name('odds.show');

        /*
         * Alerts. Daily and up, per §4, and the entitlement is checked
         * again at delivery - a customer who downgrades must stop being
         * emailed without anybody remembering to turn it off.
         */
        Route::middleware('entitled:alerts')->group(function (): void {
            Route::get('alerts', [AlertController::class, 'index'])->name('alerts');
            Route::post('alerts', [AlertController::class, 'update'])->name('alerts.update');
            Route::post('alerts/push', [AlertController::class, 'subscribe'])->name('alerts.subscribe');
            Route::delete('alerts/push', [AlertController::class, 'unsubscribe'])->name('alerts.unsubscribe');
            Route::post('alerts/watch', [AlertController::class, 'watch'])->name('alerts.watch');
            Route::delete('alerts/watch', [AlertController::class, 'unwatch'])->name('alerts.unwatch');
        });

        /*
         * My Bets. Logs a bet; never places one. Ungated: §4 gives the
         * free plan a tracker capped at 25 bets, and a cap is enforced
         * in the controller rather than by hiding the page.
         */
        Route::get('bets', [BetController::class, 'index'])->name('bets');
        Route::post('bets', [BetController::class, 'store'])->name('bets.store');
        Route::get('bets/export', [BetController::class, 'export'])
            ->middleware('throttle:10,1')->name('bets.export');

        /*
         * Bankroll: Kelly sizing and the loss limits. Pro, per §4.
         */
        Route::get('bankroll', [BankrollController::class, 'show'])
            ->middleware('entitled:bankroll')->name('bankroll');
        Route::post('bankroll', [BankrollController::class, 'update'])
            ->middleware('entitled:bankroll')->name('bankroll.update');
        Route::post('bankroll/funds', [BankrollController::class, 'funds'])
            ->middleware('entitled:bankroll')->name('bankroll.funds');

        /*
         * Sync health. Behind the login for now; it moves under the admin
         * area when there is one, at step 11. Nothing here is customer
         * data - it is how current Vigil's copy of the engine is.
         */
        /*
         * Admin. 404 rather than 403 for everybody else: a stranger
         * probing for /admin should get the same answer as one asking
         * for /nonsense.
         *
         * Reads and writes are separate controllers. Everything in the
         * first is a question and none of it can change anything; every
         * write in the second is audited and requires a typed reason.
         */
        /*
         * `admin-2fa` sits beside `admin`: holding the role is not
         * enough, the account has to have enrolled a second factor.
         * It redirects to enrolment rather than refusing, so the first
         * administrator through after this shipped is sent to set it up
         * rather than locked out of the product they run.
         */
        Route::middleware(['admin', 'admin-2fa'])->prefix('admin')->name('admin.')->group(function (): void {
            Route::get('/', [AdminController::class, 'overview'])->name('overview');
            Route::get('users', [AdminController::class, 'users'])->name('users');
            Route::get('users/{user}', [AdminController::class, 'user'])->name('user');
            Route::get('billing', [AdminController::class, 'billing'])->name('billing');
            Route::get('picks', [AdminController::class, 'picks'])->name('picks');
            Route::get('ai', [AdminController::class, 'ai'])->name('ai');
            Route::get('audit', [AdminController::class, 'audit'])->name('audit');

            /*
             * The trial funnel and what it converted to. A read page;
             * its one write is in the throttled group below.
             */
            Route::get('trials', [AdminTrialController::class, 'index'])->name('trials');

            /*
             * The shadow record: what the engine would have bet without
             * the verdict gate. Inside the admin group and nowhere else -
             * these rows are an experiment, not results, and a customer
             * must never be able to reach them.
             */
            Route::get('shadow', [AdminController::class, 'shadow'])->name('shadow');

            /*
             * The AI Learning Center and sync health move here, which is
             * where §11 puts them - they were behind the login until now
             * because admin did not exist.
             */
            Route::get('learning', LearningCenterController::class)->name('learning');
            Route::get('sync-health', SyncHealthController::class)->name('sync-health');

            /*
             * Administering the administrators. Behind `super-admin`,
             * which 404s like `admin` does - an ordinary operator does
             * not learn the page exists.
             */
            Route::middleware('super-admin')->group(function (): void {
                Route::get('admins', [AdminManagementController::class, 'index'])->name('admins');
                Route::get('roles', [AdminManagementController::class, 'roles'])->name('roles');
            });

            Route::middleware('throttle:30,1')->group(function (): void {
                Route::post('users/{user}/override', [AdminActionController::class, 'override'])->name('override');
                Route::delete('users/{user}/override', [AdminActionController::class, 'removeOverride'])->name('override.remove');
                Route::post('users/{user}/tier', [AdminActionController::class, 'tier'])->name('tier');
                Route::post('refund', [AdminActionController::class, 'refund'])->name('refund');
                Route::post('flag', [AdminActionController::class, 'flag'])->name('flag');

                /*
                 * Account administration. Each one is authorized by
                 * UserPolicy rather than by having reached this group -
                 * the middleware says you may be here, the policy says
                 * whether you may do this to this person. That is what
                 * stops an ADMIN touching a SUPER_ADMIN.
                 */
                Route::post('users', [UserManagementController::class, 'store'])->name('users.store');
                Route::post('users/{user}/edit', [UserManagementController::class, 'update'])->name('users.update');
                Route::post('users/{user}/role', [UserManagementController::class, 'role'])->name('users.role');
                Route::post('users/{user}/suspend', [UserManagementController::class, 'suspend'])->name('users.suspend');
                Route::post('users/{user}/reinstate', [UserManagementController::class, 'reinstate'])->name('users.reinstate');
                Route::post('users/{user}/revoke-sessions', [UserManagementController::class, 'revokeSessions'])->name('users.revoke');
                Route::post('users/{user}/send-reset', [UserManagementController::class, 'sendReset'])->name('users.reset');

                /*
                 * Granting or extending a trial. One action rather than
                 * two, because both end at the same place - a new
                 * expiry in the future - and two routes doing one thing
                 * is two places for the audit entry to differ.
                 */
                Route::post('trials/{user}', [AdminTrialController::class, 'grant'])->name('trials.grant');

                Route::middleware('super-admin')->group(function (): void {
                    Route::post('admins/{user}/permissions', [AdminManagementController::class, 'permissions'])->name('admins.permissions');
                    Route::post('admins/{user}/revoke', [AdminManagementController::class, 'revoke'])->name('admins.revoke');
                });
            });
        });

        /*
         * Responsible gambling. Ungated: §9 is a compliance requirement
         * and not a feature of a tier.
         */
        Route::get('account/responsible-gambling', [SafetyController::class, 'show'])->name('safety');
        Route::post('account/self-exclude', [SafetyController::class, 'exclude'])
            ->middleware('throttle:5,1')->name('safety.exclude');

        Route::get('account/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
        Route::post('account/two-factor', [TwoFactorController::class, 'store'])->name('two-factor.store');
        Route::post('account/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
        Route::delete('account/two-factor', [TwoFactorController::class, 'destroy'])->name('two-factor.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| Payment webhooks
|--------------------------------------------------------------------------
|
| Outside auth, outside CSRF, outside the verified-email group: a
| processor is a server, not a signed-in person. What takes the place of
| all three is a signature verified over the raw body and an event id
| that can only be written once.
|
| The driver name is in the path so a processor can be run alongside its
| replacement during a switchover, which is the whole point of §5 - and
| so a webhook meant for Stripe cannot be handed to the NMI verifier and
| rejected as a mystery.
|
*/
Route::post('webhooks/payments/{driver}', WebhookController::class)
    ->whereIn('driver', ['nmi', 'stripe'])
    ->middleware('throttle:120,1')
    ->name('webhooks.payments');
