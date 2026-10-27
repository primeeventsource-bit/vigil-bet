<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sign in, and the second factor that sits between the password and the
 * session.
 *
 * Nothing is logged in until both factors pass. The half-authenticated
 * state lives in the session as an id and a timestamp, never as a
 * logged-in user with a flag saying "not really" - a flag like that is one
 * forgotten middleware away from being no second factor at all.
 */
class LoginController extends Controller
{
    /** How long the half-finished login may sit before it has to start over. */
    private const CHALLENGE_TTL_SECONDS = 300;

    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureNotRateLimited($request);

        $user = User::where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($this->throttleKey($request));

            // One message for both cases. Saying "no such account" tells a
            // stranger which email addresses are worth guessing at.
            throw ValidationException::withMessages([
                'email' => 'Those details do not match an account.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put('auth.2fa', [
                'id' => $user->id,
                'remember' => $request->boolean('remember'),
                'at' => now()->timestamp,
            ]);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('today'));
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return redirect()->route('login')
                ->withErrors(['email' => 'That sign-in attempt expired. Please start again.']);
        }

        return view('auth.two-factor-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login')
                ->withErrors(['email' => 'That sign-in attempt expired. Please start again.']);
        }

        $this->ensureNotRateLimited($request, 'two-factor', (string) $user->id);

        $code = trim($validated['code']);

        // A six digit code, or a recovery code. Either finishes the login;
        // a recovery code is spent in the process and never works twice.
        $passed = $user->verifyTwoFactorCode($code) || $user->useRecoveryCode($code);

        if (! $passed) {
            RateLimiter::hit($this->throttleKey($request, 'two-factor', (string) $user->id));

            throw ValidationException::withMessages([
                'code' => 'That code is not valid.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request, 'two-factor', (string) $user->id));

        $remember = (bool) ($request->session()->get('auth.2fa.remember', false));
        $request->session()->forget('auth.2fa');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('today'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    /**
     * The user half way through signing in, if that attempt is still
     * fresh. An expired challenge is treated as no challenge at all.
     */
    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('auth.2fa');

        if (! is_array($pending) || ! isset($pending['id'], $pending['at'])) {
            return null;
        }

        if (now()->timestamp - (int) $pending['at'] > self::CHALLENGE_TTL_SECONDS) {
            $request->session()->forget('auth.2fa');

            return null;
        }

        return User::find($pending['id']);
    }

    private function ensureNotRateLimited(Request $request, string $scope = 'login', ?string $identity = null): void
    {
        $key = $this->throttleKey($request, $scope, $identity);

        if (! RateLimiter::tooManyAttempts($key, 5)) {
            return;
        }

        /*
         * Announce it. This controller throttles by hand rather than
         * through Laravel's trait, so nothing was firing the event the
         * framework defines for exactly this moment - and the audit
         * listener that records lockouts would have sat silent forever.
         *
         * The lockout is the only sign-in failure recorded. Individual
         * wrong passwords are counted by the limiter and forgotten,
         * because audit_log is append-only and un-prunable, so a row per
         * guess is storage an attacker gets to choose the size of.
         */
        event(new Lockout($request));

        throw ValidationException::withMessages([
            $scope === 'login' ? 'email' : 'code' => 'Too many attempts. Try again in '
                .RateLimiter::availableIn($key).' seconds.',
        ]);
    }

    /**
     * Keyed on the address plus whoever is being attacked.
     *
     * The second factor has no email field to key on, so it keys on the
     * pending user instead. Keying it on the address alone would let one
     * stranger on a shared network lock out everybody behind it.
     */
    private function throttleKey(Request $request, string $scope = 'login', ?string $identity = null): string
    {
        $identity ??= mb_strtolower((string) $request->input('email'));

        return $scope.'|'.$identity.'|'.$request->ip();
    }
}
