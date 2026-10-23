<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Anthropic\Client;
use Throwable;

/**
 * The real client. The only class in Vigil that imports the SDK.
 */
class AnthropicClaude implements Claude
{
    /**
     * Why the last call failed, for whoever has to fix it.
     *
     * A Read that quietly became a template is a feature degrading in
     * silence: the page still looks right, the prose is still true, and
     * nothing says the model was never reached. The caller stores this so
     * the reason survives as far as the operator.
     */
    private ?string $lastError = null;

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function ask(
        string $system,
        string $user,
        string $model,
        int $maxTokens,
        string $effort,
    ): ?ClaudeReply {
        $started = microtime(true);
        $this->lastError = null;

        try {
            $client = new Client(
                apiKey: (string) config('ai.api_key'),
                requestOptions: $this->requestOptions(),
            );

            $message = $client->messages->create(
                model: $model,
                maxTokens: $maxTokens,
                system: [[
                    'type' => 'text',
                    'text' => $system,
                    // The rules are identical on every call; the fact
                    // sheet is not. Caching this prefix is most of the
                    // input cost of a board's worth of Reads.
                    'cacheControl' => ['type' => 'ephemeral'],
                ]],
                outputConfig: ['effort' => $effort],
                messages: [['role' => 'user', 'content' => $user]],
            );

            if ($message->stopReason === 'refusal') {
                $this->lastError = 'refusal:'.($message->stopDetails?->category ?? 'unknown');

                return new ClaudeReply(body: '', refused: true);
            }

            $body = '';

            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $body .= $block->text;
                }
            }

            return new ClaudeReply(
                body: trim($body),
                inputTokens: (int) ($message->usage->inputTokens ?? 0),
                outputTokens: (int) ($message->usage->outputTokens ?? 0),
                cacheReadTokens: (int) ($message->usage->cacheReadInputTokens ?? 0),
                durationMs: (int) round((microtime(true) - $started) * 1000),
            );
        } catch (Throwable $e) {
            // Kept and reported. An API that is refusing every request for
            // a nameable reason - a key with no workspace, an expired
            // credential, a rate limit - must not look like "unavailable".
            $this->lastError = $this->summarise($e);

            report($e);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requestOptions(): array
    {
        $workspace = config('ai.workspace_id');

        if (blank($workspace)) {
            return [];
        }

        /*
         * An organisation-level key is rejected without this. The SDK has
         * no first-class parameter for it, so it goes on as a header -
         * which is exactly what the API's own error message asks for.
         */
        return ['extraHeaders' => ['anthropic-workspace-id' => (string) $workspace]];
    }

    /** The first useful line of an exception, short enough to store. */
    private function summarise(Throwable $e): string
    {
        $message = preg_replace('/\s+/', ' ', $e->getMessage()) ?? '';

        // The SDK embeds the API's JSON body in the message; the sentence
        // inside it is the part a human needs.
        if (preg_match('/"message"\s*:\s*"([^"]+)"/', $message, $m) === 1) {
            $message = $m[1];
        }

        return mb_substr(class_basename($e).': '.trim($message), 0, 300);
    }
}
