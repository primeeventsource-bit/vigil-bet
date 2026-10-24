<?php

declare(strict_types=1);

namespace App\Services\EdgeLab;

use RuntimeException;

/**
 * The engine could not be read. Never swallowed: a projection that fails
 * quietly leaves a board that looks merely quiet.
 *
 * Thinner than it was. When this crossed a network it also carried a
 * breaker flag and an isPermanent() test, for deciding whether asking
 * again would get a different answer. A local query has no such
 * distinction: either it answered, or the database is unreachable and the
 * queue's own retry is the right response to that.
 */
class EdgeLabException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $endpoint = null,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('The engine is switched off, so there is nothing to read.');
    }
}
