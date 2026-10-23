<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates the account that can actually get into the running product.
 *
 * Needed because every other way in is closed on purpose: registration
 * runs an age gate and then waits on an email nothing is configured to
 * send, and the demo login is fenced to the local environment. Without
 * this, a freshly deployed Vigil has no door.
 *
 * It prints the password once and never stores it anywhere else. It is
 * idempotent: run it again and it tops the account back up to a verified
 * subscriber without touching the password, which is what you want when
 * a tier was changed by hand or an override expired.
 */
class MakeOwnerCommand extends Command
{
    protected $signature = 'vigil:owner
        {email : The address that signs in}
        {--name= : Display name, defaults to the local part of the address}
        {--tier=pro : Which tier to put the account on}
        {--password= : Set this password instead of generating one}
        {--reset-password : Generate and set a new password for an existing account}';

    protected $description = 'Create or top up an owner account that can sign in to the running app';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $tier = (string) $this->option('tier');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('That is not an email address.');

            return self::FAILURE;
        }

        if (! array_key_exists($tier, (array) config('plans.tiers'))) {
            $this->components->error('No such tier: '.$tier);

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        $existed = $user !== null;

        // Only generated when it is actually going to be set, so an
        // idempotent re-run cannot print a password that is not the one
        // the account has.
        $password = match (true) {
            $this->option('password') !== null => (string) $this->option('password'),
            ! $existed || $this->option('reset-password') => Str::password(20, symbols: false),
            default => null,
        };

        if (! $existed) {
            $user = new User(['name' => $this->option('name') ?: Str::before($email, '@')]);
            $user->email = $email;
        }

        if ($password !== null) {
            // The model casts this as hashed; hashing it here as well would
            // store a hash of a hash and lock the account out of itself.
            $user->password = $password;
        }

        /*
         * Verified here rather than by email, because nothing is wired to
         * send one yet and an owner locked out of their own product by a
         * missing mailer is a silly way to lose an afternoon.
         */
        $user->email_verified_at ??= now();
        $user->save();

        $subscription = Subscription::updateOrCreate(
            ['user_id' => $user->id],
            [
                'tier' => $tier,
                'status' => 'active',
                // No period end: this is not a paid subscription and must
                // not look like one in the billing records later.
                'current_period_end' => null,
                'cancel_at_period_end' => false,
                'source' => 'owner_command',
            ],
        );

        Audit::write(
            $existed ? 'owner.topped_up' : 'owner.created',
            $user,
            null,
            ['email' => $email, 'tier' => $tier, 'password_set' => $password !== null],
            'vigil:owner run from the console',
        );

        $this->newLine();
        $this->components->info(($existed ? 'Topped up' : 'Created').' '.$email.' on '.$subscription->describe());

        $this->components->twoColumnDetail('Sign in at', rtrim((string) config('app.url'), '/').'/login');
        $this->components->twoColumnDetail('Email', $email);

        if ($password !== null) {
            $this->components->twoColumnDetail('Password', $password);
            $this->newLine();
            $this->components->warn('This password is shown once and is not stored. Change it after signing in.');
        } else {
            $this->components->twoColumnDetail('Password', 'unchanged (pass --reset-password to replace it)');
        }

        return self::SUCCESS;
    }
}
