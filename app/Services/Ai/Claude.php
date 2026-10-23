<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * The seam between Vigil and the model.
 *
 * The Anthropic SDK speaks PSR-18 through discovery rather than Laravel's
 * HTTP client, so nothing in the framework can intercept it - which makes
 * the call untestable unless something owns the boundary. This interface
 * owns it.
 *
 * It also keeps the SDK's shape in exactly one class. Everything above
 * this line deals in a prompt and a reply, and knows nothing about
 * content blocks, usage objects or beta flags.
 */
interface Claude
{
    /** Null when the call failed. A refusal returns a reply with refused = true. */
    public function ask(
        string $system,
        string $user,
        string $model,
        int $maxTokens,
        string $effort,
    ): ?ClaudeReply;
}
