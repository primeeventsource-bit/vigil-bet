<?php

declare(strict_types=1);

namespace App\Enums;

enum Side: string
{
    case Home = 'home';
    case Away = 'away';
    case Draw = 'draw';
    case Over = 'over';
    case Under = 'under';

    /*
     * Yes and No exist for one market: anytime touchdown scorer, which a
     * book prices as a single selection rather than two halves of a line.
     * They are reachable only from player props, which are display-only,
     * so no core market ever produces one - Market::sides() cannot return
     * them, and nothing that prices a game will see one.
     */
    case Yes = 'yes';
    case No = 'no';

    public function opposite(): self
    {
        return match ($this) {
            self::Home => self::Away,
            self::Away => self::Home,
            self::Over => self::Under,
            self::Under => self::Over,
            self::Yes => self::No,
            self::No => self::Yes,
            self::Draw => self::Draw,
        };
    }

    public function isTeamSide(): bool
    {
        return $this === self::Home || $this === self::Away;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $s) => $s->value, self::cases());
    }
}
