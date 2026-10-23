<?php

declare(strict_types=1);

namespace App\Providers;

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
    }
}
