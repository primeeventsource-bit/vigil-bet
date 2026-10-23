<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Turning the second factor on, and off again.
 *
 * Enrolment is two steps on purpose. Step one mints a secret and shows the
 * QR code; step two proves a code generated from it works, and only then
 * is 2FA considered on. Collapsing them lets somebody enable 2FA against a
 * secret they never scanned and lock themselves out of their own account.
 *
 * Turning it off needs the password. Otherwise anybody who sits down at an
 * unlocked laptop can quietly remove the factor that was protecting it.
 */
class TwoFactorController extends Controller
{
    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('auth.two-factor', [
            'enabled' => $user->hasTwoFactorEnabled(),
            'pending' => filled($user->two_factor_secret) && ! $user->hasTwoFactorEnabled(),
            'secret' => $user->two_factor_secret,
            'uri' => filled($user->two_factor_secret)
                ? Totp::provisioningUri($user->two_factor_secret, $user->email, config('brand.name'))
                : null,
            'recoveryCodes' => $request->session()->get('two-factor.recovery-codes'),
        ]);
    }

    /** Step one: mint a secret and show it. Nothing is enabled yet. */
    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return back()->with('error', 'Two-factor authentication is already on.');
        }

        $user->forceFill([
            'two_factor_secret' => Totp::generateSecret(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestep' => null,
        ])->save();

        return back()->with('status', 'Scan the code, then enter a code from your app to finish.');
    }

    /** Step two: prove a code works, then switch it on. */
    public function confirm(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:16']]);

        /** @var User $user */
        $user = $request->user();

        if (! filled($user->two_factor_secret)) {
            return back()->with('error', 'Start setup again - there is no pending secret.');
        }

        $step = Totp::verify($user->two_factor_secret, $validated['code'], $user->two_factor_last_timestep);

        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check your clock and try again.']);
        }

        $codes = User::generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_timestep' => $step,
            'two_factor_recovery_codes' => $codes,
        ])->save();

        /*
         * Flashed, not stored for display. These are shown once, now, and
         * a page that will re-show them on refresh is a page that leaves
         * them on a screen in a coffee shop.
         */
        return back()
            ->with('status', 'Two-factor authentication is on. Save your recovery codes now.')
            ->with('two-factor.recovery-codes', $codes);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate(['password' => ['required', 'string']]);

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestep' => null,
        ])->save();

        return back()->with('status', 'Two-factor authentication is off.');
    }
}
