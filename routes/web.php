<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\BetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LearningCenterController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\SyncHealthController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Marketing and legal. Public, indexable, no Livewire.
|--------------------------------------------------------------------------
*/

Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/pricing', [PageController::class, 'pricing'])->name('pricing');
Route::get('/how-it-works', [PageController::class, 'howItWorks'])->name('how-it-works');
Route::get('/record', [PageController::class, 'record'])->name('record');

Route::get('/legal/{page}', [PageController::class, 'legal'])
    ->whereIn('page', ['terms', 'privacy', 'responsible-gambling', 'disclaimer'])
    ->name('legal.page');

/*
 * Named individually as well, so a template never builds a legal URL by
 * string concatenation and no typo can produce a 404 in a footer.
 */
foreach (['terms', 'privacy', 'responsible-gambling', 'disclaimer'] as $page) {
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
        Route::view('account', 'app.account')->name('account');

        /*
         * The rest of the sidebar. Each is a real route with an honest
         * placeholder naming the step that fills it - a nav item that
         * 404s is worse than one that says "not built yet", and a nav
         * item quietly missing is worse than both.
         */
        Route::view('live-odds', 'app.placeholder', [
            'heading' => 'Live Odds',
            'step' => 'Step 4 — the board and the game page.',
        ])->name('live-odds');

        Route::view('picks', 'app.placeholder', [
            'heading' => 'Picks',
            'step' => 'Step 4 — the full value board, every pick and every pass.',
        ])->name('picks');

        Route::view('parlays', 'app.placeholder', [
            'heading' => 'Parlays',
            'step' => 'Step 8 — built on Edge Lab’s parlay judge, which is already wired.',
        ])->name('parlays');

        Route::view('analytics', 'app.placeholder', [
            'heading' => 'Analytics',
            'step' => 'Step 8 — closing line value and your own record.',
        ])->name('analytics');

        Route::view('models', 'app.placeholder', [
            'heading' => 'Models',
            'step' => 'Step 8 — which markets are proven, and the gate each was measured against.',
        ])->name('models');

        Route::view('reports', 'app.placeholder', [
            'heading' => 'Reports',
            'step' => 'Step 9 — the public track record.',
        ])->name('reports');

        Route::view('alerts', 'app.placeholder', [
            'heading' => 'Alerts',
            'step' => 'Step 10 — email and web push when a pick posts.',
        ])->name('alerts');

        // Logs a bet. Never places one; see BetController.
        Route::post('bets', BetController::class)->name('bets.store');

        /*
         * Sync health. Behind the login for now; it moves under the admin
         * area when there is one, at step 11. Nothing here is customer
         * data - it is how current Vigil's copy of the engine is.
         */
        /*
         * The AI Learning Center: whether the engine is improving,
         * read from the model versions it has written. Behind the
         * login for now; it moves under admin at step 11.
         */
        Route::get('learning', LearningCenterController::class)->name('learning');

        Route::get('sync-health', SyncHealthController::class)->name('sync-health');

        Route::get('account/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
        Route::post('account/two-factor', [TwoFactorController::class, 'store'])->name('two-factor.store');
        Route::post('account/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
        Route::delete('account/two-factor', [TwoFactorController::class, 'destroy'])->name('two-factor.destroy');
    });
});
