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
    public function ask(
        string $system,
        string $user,
        string $model,
        int $maxTokens,
        string $effort,
    ): ?ClaudeReply {
        $started = microtime(true);

        try {
            $client = new Client(apiKey: (string) config('ai.api_key'));

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
            // Reported, never swallowed: a Read that quietly became a
            // template is a feature degrading in silence.
            report($e);

            return null;
        }
    }
}
