<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\RecordAuthEvents;
use App\Models\Subscription;
use App\Models\User;
use App\Policies\AdminPolicy;
use App\Policies\SubscriptionPolicy;
use App\Policies\UserPolicy;
use App\Services\Ai\AnthropicClaude;
use App\Services\Ai\Claude;
use App\Services\Alerts\PushSender;
use App\Services\Alerts\WebPushSender;
use App\Services\EdgeLab\EdgeLabClient;
use App\Services\EdgeLab\Mirror;
use App\Services\EdgeLab\SyncHealth;
use App\Services\Entitlements\Entitlements;
use App\Services\Payments\PaymentDrivers;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\SubscriptionProjector;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class VigilServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * One health reader per process, shared by the pages that
         * page. Two instances would each keep their own failure count and
         * neither would ever reach the threshold.
         */

        $this->app->singleton(EdgeLabClient::class);
        $this->app->singleton(SyncHealth::class);
        $this->app->singleton(Mirror::class);

        /*
         * The one place the Anthropic SDK is named. Everything above it
         * deals in a prompt and a reply, which is what makes the AI layer
         * testable without a network and swappable without a rewrite.
         */
        $this->app->bind(Claude::class, AnthropicClaude::class);

        $this->app->singleton(Entitlements::class);

        /*
         * The only place a payment processor is chosen. Everything else
         * asks for the interface, which is what makes §13 true:
         * switching PAYMENT_DRIVER between `nmi` and `stripe` requires
         * no code changes.
         *
         * Bound rather than a singleton so a test can change the driver
         * mid-run and get the driver it asked for.
         */
        $this->app->bind(PaymentGateway::class, fn () => PaymentDrivers::make());

        $this->app->singleton(SubscriptionProjector::class);

        /*
         * The only place the push library is named. It speaks HTTP
         * through its own transport, so a test faking Laravel's client
         * would pass while a real notification went out - or, worse,
         * while none did and nobody noticed.
         */
        $this->app->bind(PushSender::class, WebPushSender::class);
    }

    public function boot(): void
    {
        $entitlements = $this->app->make(Entitlements::class);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Subscription::class, SubscriptionPolicy::class);

        /*
         * Administering administrators has no model of its own - an
         * admin is a User with a role - so its policy is reached by a
         * name instead. `Gate::allows('admin-management.create')`.
         */
        foreach (['viewAny', 'create', 'grantPermissions', 'revoke'] as $ability) {
            Gate::define(
                'admin-management.'.$ability,
                [AdminPolicy::class, $ability]
            );
        }

        /*
         * `is_admin` is a cache of "holds an administrative role", and
         * this is what stops it drifting from the roles that decide it.
         * A stale true would let somebody through EnsureAdmin after
         * their role was taken away this morning, and nothing about that
         * failure announces itself.
         */
        User::saved(function (User $user): void {
            if ($user->relationLoaded('roles')) {
                $user->syncAdminFlag();
            }
        });

        /*
         * Security events, caught centrally. See RecordAuthEvents for
         * why failed logins are counted rather than recorded.
         */
        Event::listen(Login::class, [RecordAuthEvents::class, 'handleLogin']);
        Event::listen(Lockout::class, [RecordAuthEvents::class, 'handleLockout']);
        Event::listen(PasswordReset::class, [RecordAuthEvents::class, 'handlePasswordReset']);

        /*
         * Every feature key becomes a gate ability, so $user->can() and
         * \@can in Blade route to the same service as the middleware.
         * Two ways to ask the question is how a Pro feature ends up
         * hidden in the UI and served by the route behind it.
         */
        foreach ($entitlements->allFeatures() as $feature) {
            Gate::define($feature, fn (?User $user) => $entitlements->can($user, $feature));
        }

        /*
         * Super Admin, in one place.
         *
         * Returning true short-circuits every ability, which is the
         * intent: an authority defined as "whatever permissions somebody
         * remembered to tick" is one that silently lacks whatever was
         * added last.
         *
         * Two deliberate exclusions, both returning null so the check
         * falls through to the real one rather than being granted:
         *
         * - Plan features. config/plans.php decides what a subscription
         *   bought, and running the product is not a purchase. Without
         *   this an operator would see a different board from the
         *   customer they are trying to help, which is the one view that
         *   needs to match.
         *
         * - Anything aimed at a Super Admin. The policies refuse those,
         *   and a before-hook that overrode them would mean two Super
         *   Admins could delete each other.
         */
        Gate::before(function (?User $user, string $ability, array $arguments = []) use ($entitlements) {
            if ($user === null || ! $user->isSuperAdmin()) {
                return null;
            }

            if (in_array($ability, $entitlements->allFeatures(), true)) {
                return null;
            }

            /*
             * Abilities no role unlocks. Without this the before-hook
             * would hand a Super Admin the very things UserPolicy
             * refuses to everybody - a `return false` written to be
             * absolute, quietly overridden one layer above it.
             */
            if (in_array($ability, (array) config('roles.never'), true)) {
                return null;
            }

            $subject = $arguments[0] ?? null;

            if ($subject instanceof User && $subject->isSuperAdmin()) {
                return null;
            }

            return true;
        });
    }
}
