<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Raises the memory ceiling for work that walks thousands of rows.
 *
 * Laravel Cloud sets `memory_limit` to 256M in a managed ini inside the
 * container, which is a quarter of what the container itself is allowed:
 * measured on 25 October 2026 the cgroup limit was 1024M against 433M in
 * use. So three quarters of the allowance was unreachable by any single
 * process, and `ingest:box-scores mlb --limit=1200` died on PHP's limit
 * while the container had 768M spare. Every backfill since has run in
 * chunks of 250 to stay under it.
 *
 * The managed ini cannot be edited to any lasting effect - the container
 * is rebuilt on every deploy - but the limit can be raised at runtime,
 * which is what this does.
 *
 * Deliberately not raised globally. A web request that needs half a
 * gigabyte is a bug and should fail loudly at 256M rather than quietly
 * succeed at 512M; batch work genuinely holds thousands of rows and is
 * the only thing here that should be allowed more.
 */
class BatchMemory
{
    /**
     * Apply the configured batch ceiling.
     *
     * Never lowers it. A caller that already has more - a developer
     * running `php -d memory_limit=1G` by hand, say - keeps it, because
     * taking headroom away from someone who asked for it would be the
     * opposite of the point.
     *
     * Returns the limit in force afterwards, so a command can say what it
     * is working with rather than leaving it to be guessed.
     */
    public static function raise(): string
    {
        $wanted = (string) config('edge.batch_memory_limit', '512M');

        $current = (string) ini_get('memory_limit');

        /*
         * -1 is unlimited, which is already more than anything configured
         * here, so it is left alone.
         */
        if ($current === '-1' || self::bytes($wanted) <= self::bytes($current)) {
            return $current;
        }

        @ini_set('memory_limit', $wanted);

        return (string) ini_get('memory_limit');
    }

    /**
     * An ini size as bytes.
     *
     * PHP writes these as "256M" and compares them as strings if you let
     * it, which makes "512M" smaller than "64M".
     */
    public static function bytes(string $limit): int
    {
        $limit = trim($limit);

        if ($limit === '' || $limit === '-1') {
            return PHP_INT_MAX;
        }

        $value = (int) $limit;

        return match (mb_strtolower(mb_substr($limit, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
