<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modeling\Predictors\NegativeBinomialPricer;
use App\Modeling\Predictors\NormalMarginPricer;
use App\Modeling\Predictors\PoissonPricer;
use App\Modeling\Predictors\PricerRegistry;
use App\Services\Ingestion\CfbdFootballSource;
use App\Services\Ingestion\EspnSource;
use App\Services\Ingestion\MlbStatsApiSource;
use App\Services\Ingestion\NflverseSource;
use App\Services\Ingestion\NhlApiSource;
use App\Services\Ingestion\SourceRegistry;
use App\Services\Ops\ApiMeter;
use App\Services\Ops\JobRunRecorder;
use App\Services\Teams\TeamResolver;
use Illuminate\Support\ServiceProvider;

/**
 * The engine's own wiring: what prices a market, and where facts come from.
 *
 * This provider used to also subscribe an auth listener, watch the User
 * model for password and email changes, and rate limit an internal HTTP
 * API. All three belonged to the engine when it was a second application
 * with its own login and its own front door. It is now a subsystem of
 * this one: Vigil owns authentication and the audit log, and the API that
 * existed solely so Vigil could read across the network is gone, because
 * both halves share a database and a process.
 */
class EdgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ApiMeter::class);
        $this->app->singleton(JobRunRecorder::class);
        $this->app->scoped(TeamResolver::class);

        $this->app->singleton(PricerRegistry::class, fn () => new PricerRegistry(
            new NormalMarginPricer,
            new PoissonPricer,
            new NegativeBinomialPricer,
        ));

        $this->app->singleton(SourceRegistry::class, fn () => new SourceRegistry([
            'nflverse' => NflverseSource::class,
            'mlb_statsapi' => MlbStatsApiSource::class,
            'nhl' => NhlApiSource::class,
            'espn' => EspnSource::class,
            'cfbd' => CfbdFootballSource::class,
        ]));
    }
}
