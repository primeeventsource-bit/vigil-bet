<?php

declare(strict_types=1);

use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

/*
 * A sign-in page left open longer than the session lives used to end at
 * Laravel's bare "419 PAGE EXPIRED" screen: a dead end reached at the
 * exact moment somebody had finished typing their password. These tests
 * hold the replacement in place.
 *
 * The CSRF middleware stands down under `php artisan test`, so pointing
 * these at a real form would prove nothing. They go at the part that is
 * actually new - what the application does once a token has already been
 * judged stale - through the web group, because the answer is a redirect
 * and a redirect needs the session that group starts.
 */
beforeEach(function (): void {
    Route::middleware('web')->post('expired-probe', function (): never {
        throw new TokenMismatchException('CSRF token mismatch.');
    });
});

it('sends a stale form back to the page it was submitted from', function (): void {
    $this->post('expired-probe', [], ['referer' => config('app.url').'/login'])
        ->assertRedirect(config('app.url').'/login');
});

it('says what happened in words a customer can act on', function (): void {
    $this->post('expired-probe', [], ['referer' => config('app.url').'/login'])
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'expired'));
});

it('gives back what was typed, so nobody fills the form in twice', function (): void {
    $this->post('expired-probe', ['email' => 'sam@example.com'], [
        'referer' => config('app.url').'/login',
    ])->assertSessionHasInput('email', 'sam@example.com');
});

it('does not hand back the password', function (): void {
    $this->post('expired-probe', ['email' => 'sam@example.com', 'password' => 'hunter2'], [
        'referer' => config('app.url').'/login',
    ])->assertSessionMissing('_old_input.password');
});

/*
 * `Referer` comes from whichever page did the posting, which on a
 * cross-site form is a page we have never seen. Following it would turn
 * an expired token into a redirect somebody else chose.
 */
it('refuses a referer pointing somewhere that is not us', function (): void {
    $this->post('expired-probe', [], ['referer' => 'https://parlayhq.online.example.com/login'])
        ->assertRedirect(route('login'));
});

it('falls back to sign-in when the browser sent no referer at all', function (): void {
    $this->post('expired-probe')->assertRedirect(route('login'));
});

/*
 * The alerts page posts with `fetch` and an `X-CSRF-TOKEN` header. A
 * redirect would be followed invisibly and read as success.
 */
it('answers a fetch caller with 419 and a message rather than a redirect', function (): void {
    $this->postJson('expired-probe')
        ->assertStatus(419)
        ->assertJsonPath('message', 'Your session expired. Reload the page and try again.');
});
