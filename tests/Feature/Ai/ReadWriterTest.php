<?php

declare(strict_types=1);

use App\Models\AiUsage;
use App\Models\VigilRead;
use App\Services\Ai\Claude;
use App\Services\Ai\ClaudeReply;
use App\Services\Ai\FactSheet;
use App\Services\Ai\ReadWriter;

beforeEach(function () {
    config()->set('ai.enabled', true);
    config()->set('ai.api_key', 'sk-ant-test');
    config()->set('ai.model', 'claude-sonnet-5-5');
    config()->set('ai.prompt_version', 1);
});

it('builds a fact sheet only from stored rows', function () {
    $entry = entryFor(['model_prob' => 0.58, 'edge_at_price' => -0.003]);

    $sheet = app(FactSheet::class)->forBoardEntry($entry);

    expect($sheet['model_probability'])->toBe(0.58)
        ->and($sheet['model_probability_written'])->toBe('58%')
        ->and($sheet['market_is_proven'])->toBeFalse()
        ->and($sheet['recommendation'])->toBe('pass')
        // Availability is collected and not modelled, and the sheet says
        // so, so a Read cannot imply an injury moved a number.
        ->and($sheet['injuries_modelled'])->toBeFalse();
});

it('stores a read whose numbers all came from the sheet', function () {
    $entry = entryFor();

    claudeReturns(paddedRead('This market has not beaten the closing line, so it is analysis rather than a pick. The model puts it at 58%.'));

    $read = app(ReadWriter::class)->forBoardEntry($entry);

    expect($read->source)->toBe('model')
        ->and($read->attempt)->toBe(1)
        ->and($read->body)->toContain('58%')
        ->and($read->fact_sheet['model_probability'])->toBe(0.58);
});

it('falls back to the template when the model keeps inventing numbers', function () {
    $entry = entryFor();

    // 91% is nowhere in the sheet. Both attempts fail; neither is stored.
    claudeReturns(paddedRead('The model puts this at 91%, which is well clear of the line.'));

    $read = app(ReadWriter::class)->forBoardEntry($entry);

    expect($read->source)->toBe('fallback')
        ->and($read->model)->toBeNull()
        ->and($read->rejected_reason)->toStartWith('unsupported_number')
        ->and($read->body)->not->toContain('91%');

    // Two calls made and two paid for, both recorded as rejected: a
    // prompt problem arrives as a bill, so it has to be visible as one.
    expect(AiUsage::count())->toBe(2)
        ->and(AiUsage::where('accepted', false)->count())->toBe(2);
});

it('accepts the second attempt when the retry behaves', function () {
    $entry = entryFor();

    claudeReturns([
        paddedRead('The model puts this at 91%.'),
        paddedRead('The model puts this at 58% and the market is not yet proven.'),
    ]);

    $read = app(ReadWriter::class)->forBoardEntry($entry);

    expect($read->source)->toBe('model')
        ->and($read->attempt)->toBe(2)
        ->and($read->body)->toContain('58%');
});

it('never shows a read that used a banned word', function () {
    $entry = entryFor();

    claudeReturns(paddedRead('This one is a lock at 58%.'));

    $read = app(ReadWriter::class)->forBoardEntry($entry);

    expect($read->source)->toBe('fallback')
        ->and($read->body)->not->toContain('lock');
});

it('writes a templated read rather than nothing when there is no api key', function () {
    config()->set('ai.enabled', false);
    config()->set('ai.api_key', null);

    $fake = claudeReturns(paddedRead('This should never be asked for.'));

    $read = app(ReadWriter::class)->forBoardEntry(entryFor());

    expect($fake->calls)->toBe(0);

    expect($read->source)->toBe('fallback')
        ->and($read->rejected_reason)->toBe('not_configured')
        // Still a real explanation, assembled from the sheet.
        ->and($read->body)->toContain('not yet been proven');
});

it('generates once per prompt version and serves it from storage after', function () {
    $entry = entryFor();

    $fake = claudeReturns(paddedRead('The market is not yet proven. The model puts this at 58%.'));

    $first = app(ReadWriter::class)->forBoardEntry($entry);
    $second = app(ReadWriter::class)->forBoardEntry($entry);

    expect($second->id)->toBe($first->id)
        ->and(VigilRead::count())->toBe(1)
        ->and($fake->calls)->toBe(1);
});

it('records what the call cost', function () {
    $entry = entryFor();

    claudeReturns(
        paddedRead('The market is not yet proven. The model puts this at 58%.'),
        inputTokens: 1_000_000,
        outputTokens: 1_000_000,
    );

    app(ReadWriter::class)->forBoardEntry($entry);

    // A million in and a million out of Sonnet 5.5 is $2 + $10.
    expect(AiUsage::first()->cost_millicents)->toBe(1_200_000)
        ->and(AiUsage::dollars(AiUsage::first()->cost_millicents))->toBe('$12.00');
});

it('never rewrites a read once it is written', function () {
    config()->set('ai.enabled', false);

    $read = app(ReadWriter::class)->forBoardEntry(entryFor());

    expect(fn () => $read->update(['body' => 'something else']))
        ->toThrow(RuntimeException::class, 'append-only');
});

it('records why the model could not be reached, not just that it could not', function () {
    $fake = claudeReturns('unused');
    // The adapter failed and said why. "api_unavailable" against a key
    // with no workspace looks identical to a rate limit, and the page
    // renders correctly either way - which is how a broken AI layer goes
    // unnoticed for a week.
    $fake->error = 'BadRequestException: This API key is not scoped to a workspace';

    app()->instance(Claude::class, new class($fake->error) implements Claude
    {
        public function __construct(private string $error) {}

        public function lastError(): ?string
        {
            return $this->error;
        }

        public function ask(string $s, string $u, string $m, int $t, string $e): ?ClaudeReply
        {
            return null;
        }
    });

    $read = app(ReadWriter::class)->forBoardEntry(entryFor());

    expect($read->source)->toBe('fallback')
        ->and($read->rejected_reason)->toContain('not scoped to a workspace');
});
