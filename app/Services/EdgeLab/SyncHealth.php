<?php

declare(strict_types=1);

namespace App\Services\EdgeLab;

use App\Models\SyncRun;

/**
 * How old the product's projection of the engine is, per feed, and
 * whether to say so.
 *
 * This exists because of one rule in the spec: a failure shows "data
 * delayed" with the age of the last good sync, and stale data is never
 * presented as live. That is only possible if something knows the
 * difference between a board with nothing on it and a board that stopped
 * being updated two hours ago - which look identical from the outside and
 * mean opposite things to somebody about to place a bet.
 */
class SyncHealth
{
    public const FEEDS = ['board', 'events', 'models'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_map(fn (string $feed) => $this->feed($feed), self::FEEDS);
    }

    /**
     * @return array<string, mixed>
     */
    public function feed(string $feed): array
    {
        $lastSuccess = SyncRun::lastSuccessFor($feed);
        $lastRun = SyncRun::query()->feed($feed)->latestFirst()->first();
        $threshold = (int) config("edgelab.stale_after_minutes.{$feed}", 60);

        $ageMinutes = $lastSuccess?->finished_at === null
            ? null
            : (int) $lastSuccess->finished_at->diffInMinutes(now());

        return [
            'feed' => $feed,
            'configured' => (bool) config('edgelab.enabled'),
            'last_success_at' => $lastSuccess?->finished_at,
            'age_minutes' => $ageMinutes,
            'stale_after_minutes' => $threshold,
            'is_stale' => $this->isStale($feed),
            'never_synced' => $lastSuccess === null,
            'last_status' => $lastRun?->status,
            'last_message' => $lastRun?->message,
            'cursor' => $lastSuccess?->cursor_after,
            'records_written' => $lastSuccess?->records_written,
        ];
    }

    /**
     * Whether this feed's data should carry a "data delayed" badge.
     *
     * Never having synced counts as stale. The alternative - treating an
     * empty history as fine until proven otherwise - means a brand new
     * deployment with a broken token renders a confident, empty board.
     */
    public function isStale(string $feed): bool
    {
        if (! config('edgelab.enabled')) {
            return true;
        }

        $lastSuccess = SyncRun::lastSuccessFor($feed);

        if ($lastSuccess?->finished_at === null) {
            return true;
        }

        return $lastSuccess->finished_at->diffInMinutes(now())
            > (int) config("edgelab.stale_after_minutes.{$feed}", 60);
    }

    /** Any feed stale at all. What the app shell uses to decide to warn. */
    public function anyStale(?array $feeds = null): bool
    {
        foreach ($feeds ?? self::FEEDS as $feed) {
            if ($this->isStale($feed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One plain sentence for a customer, or null when everything is fine.
     *
     * Says how old the data is rather than that something went wrong. A
     * customer does not need to know which of our feeds failed; they need
     * to know whether to trust what is on the screen.
     */
    public function customerNotice(?array $feeds = null): ?string
    {
        if (! config('edgelab.enabled')) {
            return 'Live data is not connected yet, so nothing on this page is current.';
        }

        $oldest = null;

        /*
         * Scoped to the feeds the page actually renders from. A banner on
         * the daily card reading "not synced yet" because the odds feed
         * is behind would be true of something and false of what the
         * customer is looking at, which is a worse failure than silence.
         */
        foreach ($feeds ?? self::FEEDS as $feed) {
            if (! $this->isStale($feed)) {
                continue;
            }

            $lastSuccess = SyncRun::lastSuccessFor($feed);

            if ($lastSuccess?->finished_at === null) {
                return 'Data delayed: this has not synced yet. Nothing here is current.';
            }

            $age = (int) $lastSuccess->finished_at->diffInMinutes(now());
            $oldest = max($oldest ?? 0, $age);
        }

        if ($oldest === null) {
            return null;
        }

        return 'Data delayed: last updated '.$this->humanise($oldest).' ago.';
    }

    private function humanise(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' '.str('minute')->plural($minutes);
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            return $hours.' '.str('hour')->plural($hours);
        }

        $days = intdiv($hours, 24);

        return $days.' '.str('day')->plural($days);
    }
}
