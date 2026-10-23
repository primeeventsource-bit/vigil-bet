<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiUsage;
use App\Models\VigilRead;
use App\Models\VlBoardEntry;

/**
 * Writes the Vigil Read.
 *
 * The model is given a fact sheet and asked to explain it in plain
 * English. It is never asked who will win, never shown a question it
 * could answer with a prediction, and never trusted with a figure it was
 * not handed.
 *
 * The flow is: generate, validate, and on failure generate once more with
 * the failure named. If the second attempt also fails, fall back to the
 * templated explanation, which is assembled by concatenation and
 * therefore cannot invent anything. An unvalidated Read is never stored
 * and never shown.
 */
class ReadWriter
{
    public function __construct(
        private readonly Claude $claude,
        private readonly FactSheet $facts,
        private readonly ReadValidator $validator,
        private readonly TemplatedRead $template,
    ) {}

    public function isConfigured(): bool
    {
        return (bool) config('ai.enabled') && filled(config('ai.api_key'));
    }

    /**
     * The Read for a board entry, generated once per prompt version and
     * served from storage thereafter.
     */
    public function forBoardEntry(VlBoardEntry $entry, bool $bulk = false): VigilRead
    {
        $version = (int) config('ai.prompt_version');

        $existing = VigilRead::query()
            ->where('subject_type', 'board_entry')
            ->where('subject_id', $entry->id)
            ->where('prompt_version', $version)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->write('board_entry', $entry->id, $this->facts->forBoardEntry($entry), $version, $bulk);
    }

    /** @param  array<string, mixed>  $sheet */
    private function write(string $type, int $id, array $sheet, int $version, bool $bulk): VigilRead
    {
        $model = $bulk ? (string) config('ai.bulk_model') : (string) config('ai.model');
        $lastReason = null;

        if ($this->isConfigured()) {
            foreach ([1, 2] as $attempt) {
                $result = $this->generate($sheet, $model, $attempt === 2 ? $lastReason : null);

                if ($result === null) {
                    break;
                }

                $check = $this->validator->check($result['body'], $sheet);

                $this->recordUsage('read.'.$type, $model, $result, $check['ok'], $check['reason']);

                if ($check['ok']) {
                    return $this->store($type, $id, $version, $result['body'], 'model', $model, $sheet, $attempt, null);
                }

                $lastReason = $check['reason'].($check['offending'] ? ' ('.implode(', ', $check['offending']).')' : '');
            }
        }

        /*
         * Two failed attempts, a missing key, or an API error. The
         * customer gets the plainer explanation rather than a gap, and
         * the row records which it was.
         */
        return $this->store(
            $type, $id, $version,
            $this->template->compose($sheet),
            'fallback', null, $sheet, null,
            $lastReason ?? ($this->isConfigured() ? 'api_unavailable' : 'not_configured'),
        );
    }

    /**
     * One call to the model, through the seam.
     *
     * @param  array<string, mixed>  $sheet
     * @return array{body:string, usage:array<string, int>, duration_ms:int}|null
     */
    private function generate(array $sheet, string $model, ?string $retryBecause): ?array
    {
        $user = 'Fact sheet:
'.json_encode($sheet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($retryBecause !== null) {
            $user .= '

Your previous attempt was rejected: '.$retryBecause.'. '
                .'Write it again using only the figures above, exactly as they are written.';
        }

        $reply = $this->claude->ask(
            $this->systemPrompt(),
            $user,
            $model,
            (int) config('ai.max_tokens'),
            (string) config('ai.effort'),
        );

        // A failed call and a safety decline are both "no explanation",
        // and both fall through to the template.
        if ($reply === null || $reply->refused) {
            return null;
        }

        return [
            'body' => $reply->body,
            'usage' => [
                'input' => $reply->inputTokens,
                'output' => $reply->outputTokens,
                'cache_read' => $reply->cacheReadTokens,
            ],
            'duration_ms' => $reply->durationMs,
        ];
    }

    /**
     * The rules, as the spec states them.
     *
     * Written as prohibitions rather than preferences because every one
     * of them is a thing that would otherwise happen: models predict
     * winners when asked about games, reach for round numbers that were
     * not given, and write like a tipster when the subject is betting.
     */
    private function systemPrompt(): string
    {
        $brand = config('brand.name');
        $rgLine = config('vigil.responsible_gambling_line');

        return <<<PROMPT
        You write short explanations for {$brand}, a sports betting analytics product.

        You are given a fact sheet of figures that have already been calculated by a
        statistical engine. Your only job is to explain what those figures mean, in
        plain English, to someone deciding whether to place a bet.

        Rules, all of them absolute:

        1. Use only the fact sheet. You have no other knowledge of this game, these
           teams, or this market, and you must not write as though you do.
        2. Never predict a winner. You are not being asked who will win. You are
           explaining what the engine measured.
        3. Never state a number that is not in the fact sheet. Quote figures exactly
           as they are written there. If you want to say something the sheet does not
           support with a number, say it without one.
        4. Always say whether the market is proven. If market_is_proven is false, say
           plainly that this market has not yet beaten the closing line and is
           analysis rather than a pick.
        5. If the recommendation is a pass, give the reason in one sentence.
        6. Write 60 to 120 words. One paragraph. No headings, no bullet points, no
           markdown.
        7. No hype. Never write that something is guaranteed, a lock, free money, a
           sure thing, or cannot lose. Say "pass" with the same confidence as "pick".
        8. End with exactly this sentence, and only when the recommendation is a bet
           in a proven market: {$rgLine}

        Write only the explanation. No preamble, no sign-off, no commentary on these
        instructions.
        PROMPT;
    }

    /** @param  array<string, mixed>  $sheet */
    private function store(
        string $type,
        int $id,
        int $version,
        string $body,
        string $source,
        ?string $model,
        array $sheet,
        ?int $attempt,
        ?string $rejected,
    ): VigilRead {
        return VigilRead::create([
            'subject_type' => $type,
            'subject_id' => $id,
            'prompt_version' => $version,
            'body' => $body,
            'source' => $source,
            'model' => $model,
            'fact_sheet' => $sheet,
            'attempt' => $attempt,
            'rejected_reason' => $rejected,
            'created_at' => now(),
        ]);
    }

    /** @param  array{usage:array<string, int>, duration_ms:int}  $result */
    private function recordUsage(string $purpose, string $model, array $result, bool $accepted, ?string $outcome): void
    {
        $prices = (array) config('ai.pricing.'.$model, ['input' => 0, 'output' => 0]);

        // Millicents, integer. A Read costs a fraction of a cent, and
        // floats would round a month of them to nothing.
        $cost = (int) round(
            ($result['usage']['input'] / 1_000_000 * (float) ($prices['input'] ?? 0)
                + $result['usage']['output'] / 1_000_000 * (float) ($prices['output'] ?? 0)) * 100_000
        );

        AiUsage::create([
            'purpose' => $purpose,
            'model' => $model,
            'input_tokens' => $result['usage']['input'],
            'output_tokens' => $result['usage']['output'],
            'cache_read_tokens' => $result['usage']['cache_read'],
            'cost_millicents' => $cost,
            'accepted' => $accepted,
            'outcome' => $outcome,
            'duration_ms' => $result['duration_ms'],
            'created_at' => now(),
        ]);
    }
}
