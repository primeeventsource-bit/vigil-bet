<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The Vigil Read
|--------------------------------------------------------------------------
|
| A short, plain-English explanation attached to a pick, a pass, a parlay
| grade or a customer's own bet.
|
| The one rule everything here exists to enforce: the model explains
| numbers and never produces them. It is handed a fact sheet built only
| from stored rows, it is never asked who will win, and every figure in
| what it writes must already appear in that sheet. An explanation that
| invents a number is worse than no explanation, because it reads exactly
| like one that did not.
|
*/

return [

    'enabled' => filled(env('ANTHROPIC_API_KEY')),

    'api_key' => env('ANTHROPIC_API_KEY'),

    /*
     * Required when the key is not itself scoped to a workspace -
     * Anthropic then rejects the request and names this header. An
     * organisation-level key plus this id, or a workspace-scoped key
     * and no id; either works, and neither is guessable from the key,
     * which is why the failure has to say so out loud rather than
     * degrade quietly to the template.
     */
    'workspace_id' => env('ANTHROPIC_WORKSPACE_ID'),

    /*
     * Sonnet for a Read a customer reads; Haiku for bulk backfills where
     * the volume matters more than the prose. Both are current-generation.
     */
    'model' => env('VIGIL_AI_MODEL', 'claude-sonnet-5-5'),
    'bulk_model' => env('VIGIL_AI_BULK_MODEL', 'claude-haiku-5-5'),

    /*
     * Reads are generated once per (board entry, prompt version) and served
     * from storage. Bump this when the prompt changes and the next sync
     * regenerates; the old Read stays on disk, because what a customer was
     * actually shown is part of the record.
     *
     * 2: research - form, availability, rest, weather - started reaching
     * the fact sheet. Without the bump every existing Read would stay as
     * it was, written from prices alone, while claiming a prompt version
     * that promises more.
     */
    'prompt_version' => (int) env('VIGIL_AI_PROMPT_VERSION', 2),

    /*
     * A Read is 60-120 words. max_tokens is a ceiling, not a target, and
     * generous enough that a Read is never cut off mid-sentence - a
     * truncated explanation would fail validation and cost a retry.
     */
    'max_tokens' => (int) env('VIGIL_AI_MAX_TOKENS', 1024),

    /*
     * Low effort, deliberately. The task is to restate figures that are
     * already decided in plain words. There is nothing here to reason
     * about, and higher effort would buy longer latency and a larger bill
     * for the same paragraph.
     */
    'effort' => env('VIGIL_AI_EFFORT', 'low'),

    /*
     * Dollars per million tokens, for the cost column on the admin page.
     * Kept here rather than fetched: a price that silently changes under a
     * stored cost figure makes the history unreadable.
     */
    'pricing' => [
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
    ],

    /*
     * Words a Read may never contain. Not a style guide - §0.7 forbids
     * this language outright, and a model that reaches for it has
     * misunderstood the job badly enough that the output is not worth
     * showing even if every number in it is right.
     */
    'banned_words' => [
        'guaranteed', 'guarantee', 'lock', 'locked', 'can\'t lose', 'cannot lose',
        'sure thing', 'free money', 'easy money', 'no-brainer', 'slam dunk',
        'hammer', 'max bet', 'bet the house', 'risk-free', 'riskless',
    ],

    /*
     * Customer-triggered Reads, per tier, per day. Reads attached to the
     * board are generated once and shared, so these only limit the ones a
     * customer asks for on their own bets and parlays.
     */
    'rate_limits' => [
        'free' => (int) env('VIGIL_AI_LIMIT_FREE', 1),
        'daily' => (int) env('VIGIL_AI_LIMIT_DAILY', 50),
        'pro' => (int) env('VIGIL_AI_LIMIT_PRO', 200),
    ],

];
