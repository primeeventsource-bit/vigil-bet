<?php

declare(strict_types=1);

use App\Services\Ai\ReadValidator;

/*
 * The check that makes the AI layer safe to ship. A language model
 * writing about probabilities will occasionally produce a number that is
 * plausible, well-placed and wrong, and in a betting product that is not
 * a typo - it is the thing the customer acted on.
 */

beforeEach(function () {
    $this->validator = new ReadValidator;

    $this->sheet = [
        'selection' => 'Bills -3.5',
        'market_is_proven' => true,
        'recommendation' => 'bet',
        'model_probability' => 0.76,
        'model_probability_written' => '76%',
        'edge_at_this_price' => 0.124,
        'edge_at_this_price_written' => '12.4%',
        'price_american' => -110,
        'price_written' => '-110',
        'book' => 'Hard Rock Bet',
    ];
});

function body(string $text): string
{
    // Padded to a realistic length so the length rule is not what fails.
    return $text.' '.str_repeat('The engine measured this against the closing line and recorded the result. ', 8);
}

it('accepts a read whose every figure came from the sheet', function () {
    $result = $this->validator->check(
        body('The model puts Bills -3.5 at 76%, which is 12.4% above the break-even rate at -110 with Hard Rock Bet.'),
        $this->sheet,
    );

    expect($result['ok'])->toBeTrue();
});

it('rejects a number the sheet never contained', function () {
    // 68% is plausible, well-placed and invented. This is the whole point.
    $result = $this->validator->check(
        body('The model puts this at 68%, comfortably above the break-even rate.'),
        $this->sheet,
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toBe('unsupported_number')
        ->and($result['offending'])->toContain('68%');
});

it('rejects an invented price even when every other figure is right', function () {
    $result = $this->validator->check(
        body('At 76% against a 12.4% edge, the -120 on offer still clears the bar.'),
        $this->sheet,
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['offending'])->toContain('-120');
});

it('accepts a figure written with a plus the sheet omits', function () {
    $sheet = $this->sheet;
    $sheet['price_american'] = 120;
    $sheet['price_written'] = '+120';

    $result = $this->validator->check(
        body('The price is +120, and the model puts this at 76%.'),
        $sheet,
    );

    expect($result['ok'])->toBeTrue();
});

it('lets ordinary small numbers through without demanding they be facts', function () {
    // "one paragraph", "three markets" - prose, not claims about the bet.
    $result = $this->validator->check(
        body('There are 3 markets here and the model puts this one at 76%.'),
        $this->sheet,
    );

    expect($result['ok'])->toBeTrue();
});

it('refuses hype however correct the arithmetic is', function (string $word) {
    $result = $this->validator->check(
        body("This is a {$word}. The model puts it at 76% with a 12.4% edge."),
        $this->sheet,
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toStartWith('banned_word:');
})->with(['lock', 'guarantee', 'sure thing', 'no-brainer']);

it('does not mistake an innocent word for a banned one', function () {
    // "unlocked" contains "lock". Word boundaries, not substrings.
    $result = $this->validator->check(
        body('The price unlocked a 12.4% edge once the model reached 76%.'),
        $this->sheet,
    );

    expect($result['ok'])->toBeTrue();
});

it('rejects something far too short or far too long to be a read', function () {
    expect($this->validator->check('Bet it.', $this->sheet)['ok'])->toBeFalse();

    $long = str_repeat('The model measured the market against the closing line again. ', 40);

    expect($this->validator->check($long, $this->sheet)['reason'])->toStartWith('length:');
});

it('rejects an empty answer rather than storing a blank read', function () {
    expect($this->validator->check('   ', $this->sheet)['reason'])->toBe('empty');
});

it('accepts a probability quoted as a percentage of a stored decimal', function () {
    // The sheet holds 0.76; the Read says 76%. Both must pass.
    $result = $this->validator->check(
        body('The model puts this at 76% and the edge at 12.4%.'),
        ['model_probability' => 0.76, 'edge_at_this_price' => 0.124],
    );

    expect($result['ok'])->toBeTrue();
});
