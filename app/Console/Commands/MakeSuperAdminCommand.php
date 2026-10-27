<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Provisions the account that administers the platform.
 *
 * Deliberately different from vigil:owner in the one way that matters:
 * this command never sets a password and never prints one. vigil:owner
 * does both, which is right for bootstrapping a developer account into
 * an environment with no mailer - and wrong for the most privileged
 * account in the product, where a password that existed in a terminal
 * once is a password that exists in a scrollback buffer forever.
 *
 * Instead it sends the address a signed, expiring link and lets whoever
 * controls that mailbox choose a secret nobody else ever sees. Ownership
 * of the mailbox is the proof of identity, which is the same proof every
 * password reset in the product already relies on.
 *
 * Idempotent. Re-running it on an existing account re-sends the
 * invitation and changes nothing else - no password reset, no role
 * churn, no audit entry claiming a creation that did not happen.
 *
 * The account cannot reach /admin until it has enrolled a second factor;
 * RequireAdminTwoFactor sees to that. So the sequence a new Super Admin
 * walks is: receive link, set password, sign in, enrol MFA, in.
 */
class MakeSuperAdminCommand extends Command
{
    protected $signature = 'vigil:super-admin
                            {email : The address that will hold SUPER_ADMIN}
                            {--name= : Display name, defaults to the local part of the address}
                            {--no-invite : Assign the role but send no activation email}
                            {--dry-run : Report what would happen and change nothing}';

    protected $description = 'Provision the Super Admin account and invite it to set a password';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('Not an email address: '.$email);

            return self::FAILURE;
        }

        $role = (string) config('roles.super_admin');
        $dry = (bool) $this->option('dry-run');

        $user = User::query()->where('email', $email)->first();
        $existed = $user !== null;

        $this->components->twoColumnDetail('Address', $email);
        $this->components->twoColumnDetail('Account', $existed ? 'exists (#'.$user->getKey().')' : 'will be created');
        $this->components->twoColumnDetail('Role', $role);

        if ($dry) {
            $this->components->info('Dry run. Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $existed) {
            $user = new User([
                'name' => (string) ($this->option('name') ?: Str::before($email, '@')),
            ]);
            $user->email = $email;

            /*
             * A random secret nobody will ever learn, because the
             * activation link is the only way in. Leaving the column
             * null would be worse: some code paths treat an empty
             * password as "not set yet" and others as a hash that
             * nothing can match, and only one of those is safe.
             */
            $user->password = Str::password(40);

            /*
             * Verified on creation. The address is being proved right
             * now by the activation link - requiring a second,
             * separate proof of the same mailbox would be ceremony,
             * and it would leave the account unable to reach the
             * `verified` group that the admin pages sit inside.
             */
            $user->email_verified_at = now();
            $user->save();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        // Also a plain USER, so anything reading the member role finds
        // one. A Super Admin is still a person with an account.
        if (! $user->hasRole((string) config('roles.user'))) {
            $user->assignRole((string) config('roles.user'));
        }

        $user->load('roles');
        $user->syncAdminFlag();

        Audit::write(
            $existed ? 'super_admin.granted' : 'super_admin.created',
            $user,
            null,
            ['email' => $email, 'role' => $role],
            'vigil:super-admin run from the console'
        );

        $this->components->twoColumnDetail('Roles', $user->roles->pluck('name')->implode(', '));
        $this->components->twoColumnDetail('Admin flag', $user->isAdmin() ? 'true' : 'false');

        if ($this->option('no-invite')) {
            $this->components->warn('No invitation sent (--no-invite). The account has no usable password.');

            return self::SUCCESS;
        }

        return $this->invite($user->email);
    }

    /**
     * Send the activation link.
     *
     * This is Laravel's password broker, not a bespoke token: it is
     * already signed, already expiring, already single-use, and already
     * the thing the reset-password route knows how to consume. A second
     * token system would be a second thing to get wrong.
     */
    private function invite(string $email): int
    {
        try {
            $status = Password::sendResetLink(['email' => $email]);
        } catch (Throwable $e) {
            // The transport's own words. "Failed to send" is not an
            // answer anybody can act on.
            $this->components->error(get_class($e));
            $this->line('  '.$e->getMessage());

            return self::FAILURE;
        }

        if ($status !== Password::RESET_LINK_SENT) {
            $this->components->error('Invitation not sent: '.$status);

            return self::FAILURE;
        }

        $this->components->info('Activation link sent to '.$email.'.');
        $this->line('  It expires, and it is the only way to set the password.');
        $this->line('  Signing in then requires enrolling a second factor before /admin opens.');

        return self::SUCCESS;
    }
}
