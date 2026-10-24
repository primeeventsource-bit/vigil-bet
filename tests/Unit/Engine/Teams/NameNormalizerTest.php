<?php

declare(strict_types=1);

use App\Services\Teams\NameNormalizer;

it('normalises punctuation and case', function () {
    expect(NameNormalizer::normalize('St. Louis Cardinals'))->toBe('saint louis cardinals')
        ->and(NameNormalizer::normalize('LA Clippers'))->toBe('la clippers')
        ->and(NameNormalizer::normalize('  Boston   Red Sox '))->toBe('boston red sox');
});

it('expands ampersands', function () {
    expect(NameNormalizer::normalize('Texas A&M'))->toBe('texas a and m');
});

it('strips university noise from college names', function () {
    expect(NameNormalizer::normalize('University of Kansas'))->toBe('kansas');
});

it('builds a stable slug', function () {
    expect(NameNormalizer::slug('Buffalo Bills'))->toBe('buffalo-bills')
        ->and(NameNormalizer::slug('San Francisco 49ers'))->toBe('san-francisco-49ers');
});

it('transliterates accents instead of splitting words', function () {
    // The NHL feed spells Montreal with an accent and the odds feed does
    // not. Both must land on the same key.
    expect(NameNormalizer::normalize('Montréal Canadiens'))->toBe('montreal canadiens')
        ->and(NameNormalizer::normalize('San José Sharks'))->toBe('san jose sharks')
        ->and(NameNormalizer::slug('Montréal Canadiens'))->toBe('montreal-canadiens');
});

it('removes an apostrophe rather than splitting the word on it', function () {
    // ESPN writes Hawai'i and the odds feed writes Hawaii. Turning the mark
    // into a space, as every other punctuation mark is, gave the two
    // sources different keys and quietly dropped every game that team
    // played: 25 unmatched hits in two days.
    expect(NameNormalizer::normalize("Hawai'i Rainbow Warriors"))->toBe('hawaii rainbow warriors')
        ->and(NameNormalizer::normalize('Hawaii Rainbow Warriors'))->toBe('hawaii rainbow warriors')
        ->and(NameNormalizer::normalize("St. John's Red Storm"))->toBe('saint johns red storm');
});

it('folds every apostrophe a source might send', function () {
    // The Hawaiian okina and a typographic quote are different characters
    // from an ASCII apostrophe, and a feed may send any of the three.
    expect(NameNormalizer::normalize("Hawai\u{02bb}i Rainbow Warriors"))->toBe('hawaii rainbow warriors')
        ->and(NameNormalizer::normalize("Hawai\u{2019}i Rainbow Warriors"))->toBe('hawaii rainbow warriors')
        ->and(NameNormalizer::slug("Hawai'i Rainbow Warriors"))->toBe('hawaii-rainbow-warriors');
});

it('still splits on marks that join separate words', function () {
    // The apostrophe is the exception, not the new rule: a hyphen or a
    // slash is standing between two words and has to become a space.
    expect(NameNormalizer::normalize('Texas A&M-Corpus Christi'))->toBe('texas a and m corpus christi')
        ->and(NameNormalizer::normalize('Miami (OH) RedHawks'))->toBe('miami oh redhawks');
});
