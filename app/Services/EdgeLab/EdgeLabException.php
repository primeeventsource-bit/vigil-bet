<?php

declare(strict_types=1);

namespace App\Services\EdgeLab;

use RuntimeException;

/**
 * Edge Lab could not be read. Never swallowed: a sync that fails quietly
 * leaves a board that looks merely quiet.
 */
class EdgeLabException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $endpoint = null,
        public readonly bool $breakerOpen = false,
    ) {
        parent::__construct($message);
    }

    public static function breakerOpen(?int $retryIn): self
    {
        return new self(
            $retryIn === null
                ? 'Edge Lab is being left alone after repeated failures.'
                : "Edge Lab is being left alone after repeated failures; retrying in about {$retryIn}s.",
            breakerOpen: true,
        );
    }

    public static function notConfigured(): self
    {
        return new self('EDGE_LAB_TOKEN is not set, so Edge Lab cannot be read.');
    }

    /** A 4xx is a disagreement about the request; retrying asks the same question. */
    public function isPermanent(): bool
    {
        return $this->status !== null && $this->status >= 400 && $this->status < 500 && $this->status !== 429;
    }
}
