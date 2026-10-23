<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AgeAttestation;
use App\Models\User;
use App\Support\Age;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Signup, with the age gate attached to it rather than bolted on after.
 *
 * The gate is part of the same request and the same transaction as the
 * account. A flow that creates the user first and asks their age on the
 * next screen has a window in which an under-age account exists and is
 * logged in, and that window is exactly what a regulator asks about.
 */
class RegisterController extends Controller
{
    public function show(): View
    {
        return view('auth.register', [
            'minimumAge' => Age::minimum(),
            'latestEligibleBirthDate' => Age::latestEligibleBirthDate()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $minimum = Age::minimum();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'birth_date' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            // Two separate things, and both are required. The arithmetic
            // says they are old enough; the box says they claim to be.
            'age_attestation' => ['accepted'],
            'terms' => ['accepted'],
        ], [
            'age_attestation.accepted' => "You must confirm you are at least {$minimum} years old.",
            'terms.accepted' => 'You must accept the terms to create an account.',
        ]);

        $birthDate = Carbon::parse($validated['birth_date']);

        /*
         * Checked on the server, always. The form carries a `max` on the
         * date field, which stops an honest mistake and stops nothing
         * else - it is a convenience, never the gate.
         */
        if (! Age::meetsMinimum($birthDate)) {
            $this->recordRefusal($request, $birthDate, $minimum);

            return back()
                ->withInput($request->except(['password', 'password_confirmation', 'birth_date']))
                ->withErrors(['birth_date' => "You must be at least {$minimum} to use ".config('brand.name').'.']);
        }

        $user = DB::transaction(function () use ($validated, $birthDate, $minimum, $request): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            // The year only. The full date dies with this request.
            AgeAttestation::create([
                'user_id' => $user->id,
                'birth_year' => $birthDate->year,
                'minimum_age' => $minimum,
                'passed' => true,
                'attested' => true,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('verification.notice')
            ->with('status', 'Account created. Check your email to confirm the address.');
    }

    /**
     * A refusal is worth a row too.
     *
     * Without one there is no evidence the gate ever turned anybody away,
     * and "we check" is a claim rather than a record. It carries no
     * user_id because no user was created, so it is written straight to
     * the table rather than through the relation.
     */
    private function recordRefusal(Request $request, Carbon $birthDate, int $minimum): void
    {
        DB::table('age_attestations')->insert([
            'user_id' => null,
            'birth_year' => $birthDate->year,
            'minimum_age' => $minimum,
            'passed' => false,
            'attested' => true,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }
}
