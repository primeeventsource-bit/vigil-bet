<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\AnthropicClaude;
use App\Services\Ai\Claude;
use App\Services\EdgeLab\CircuitBreaker;
use App\Services\EdgeLab\EdgeLabClient;
use App\Services\EdgeLab\Mirror;
use App\Services\EdgeLab\SyncHealth;
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
    }
}
