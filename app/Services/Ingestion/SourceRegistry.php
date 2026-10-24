<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\Sport;
use RuntimeException;

/**
 * Maps a sport plus a role (schedules, results, team_stats, starters) to the
 * adapter configured for it in config/sports.php.
 */
class SourceRegistry
{
    /** @var array<string, class-string<SportDataSource>> */
    private array $map = [];

    /** @param  array<string, class-string<SportDataSource>>  $map */
    public function __construct(array $map = [])
    {
        $this->map = $map;
    }

    public function register(string $key, string $class): void
    {
        $this->map[$key] = $class;
    }

    public function forRole(Sport $sport, string $role): ?SportDataSource
    {
        $key = $sport->config("sources.{$role}");

        if ($key === null) {
            return null;
        }

        return $this->make((string) $key, $sport);
    }

    public function make(string $key, Sport $sport): SportDataSource
    {
        if (! isset($this->map[$key])) {
            throw new RuntimeException("No data source registered for key [{$key}].");
        }

        return app($this->map[$key], ['sport' => $sport]);
    }

    /** @return array<string, class-string<SportDataSource>> */
    public function all(): array
    {
        return $this->map;
    }
}
