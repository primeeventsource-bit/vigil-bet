<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\Ai\AnthropicClaude;
use App\Services\Ai\Claude;
use App\Services\EdgeLab\CircuitBreaker;
use App\Services\EdgeLab\EdgeLabClient;
use App\Services\EdgeLab\Mirror;
use App\Services\EdgeLab\SyncHealth;
use App\Services\Entitlements\Entitlements;
use App\Services\Payments\PaymentDrivers;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\SubscriptionProjector;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class VigilServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * One breaker per process, shared by the client and the health
         * page. Two instances would each keep their own failure count and
         * neither would ever reach the threshold.
         */
        $this->app->singleton(CircuitBreaker::class);

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
    }

    public function boot(): void
    {
        $entitlements = $this->app->make(Entitlements::class);

        /*
         * Every feature key becomes a gate ability, so $user->can() and
         * \@can in Blade route to the same service as the middleware.
         * Two ways to ask the question is how a Pro feature ends up
         * hidden in the UI and served by the route behind it.
         */
        foreach ($entitlements->allFeatures() as $feature) {
            Gate::define($feature, fn (?User $user) => $entitlements->can($user, $feature));
        }
    }
}
