<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Models\PushSubscription;

/**
 * The seam between the product and whatever actually sends a push.
 *
 * Here for the same reason the Claude interface is: the real sender
 * speaks HTTP through a library that does its own transport, so a test
 * faking Laravel's HTTP client would pass while a real notification went
 * out - or, worse, while none did and nobody noticed.
 */
interface PushSender
{
    public function configured(): bool;

    /**
     * Returns null on success, or why it failed.
     *
     * A string rather than an exception because a dead subscription is a
     * normal outcome, not an error: browsers get uninstalled and the
     * right response is to record it and move on.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(PushSubscription $subscription, array $payload): ?string;

    /** Whether that failure means the subscription is gone for good. */
    public function isGone(string $failure): bool;
}
