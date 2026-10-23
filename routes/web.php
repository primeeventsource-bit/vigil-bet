<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\PageController;
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
        Route::view('today', 'app.today')->name('today');
        Route::view('account', 'app.account')->name('account');

        Route::get('account/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
        Route::post('account/two-factor', [TwoFactorController::class, 'store'])->name('two-factor.store');
        Route::post('account/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
        Route::delete('account/two-factor', [TwoFactorController::class, 'destroy'])->name('two-factor.destroy');
    });
});
