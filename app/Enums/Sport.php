<?php

declare(strict_types=1);

namespace App\Enums;

enum Sport: string
{
    case Nfl = 'nfl';
    case Nba = 'nba';
    case Mlb = 'mlb';
    case Nhl = 'nhl';
    case Ncaaf = 'ncaaf';
    case Ncaab = 'ncaab';

    public function label(): string
    {
        return (string) config("sports.{$this->value}.label", strtoupper($this->value));
    }

    public function config(?string $key = null): mixed
    {
        $path = 'sports.'.$this->value.($key ? '.'.$key : '');

        return config($path);
    }

    public function oddsApiKey(): string
    {
        return (string) $this->config('odds_api_key');
    }

    public function markets(): array
    {
        return array_map(
            static fn (string $m) => Market::from($m),
            (array) $this->config('markets')
        );
    }

    /**
     * Player prop market keys for this sport, as The Odds API names them.
     *
     * Deliberately raw strings and not a Market enum. Market exists so the
     * three core markets can be matched exhaustively - sides(), hasLine(),
     * the pricers, the selection labels all switch on it - and a prop has
     * none of those answers. Empty for a sport whose props are not worth
     * the credits yet, which is most of them.
     *
     * @return array<int, string>
     */
    public function propMarkets(): array
    {
        return array_values(array_filter(
            array_map('strval', (array) $this->config('prop_markets'))
        ));
    }

    public function hasProps(): bool
    {
        return $this->propMarkets() !== [];
    }

    /** @return array<int, self> Sports with props configured and enabled. */
    public static function withProps(): array
    {
        if (! (bool) config('edge.odds_api.props.enabled')) {
            return [];
        }

        return array_values(array_filter(self::enabled(), static fn (self $s) => $s->hasProps()));
    }

    public function isEnabled(): bool
    {
        return in_array($this->value, (array) config('edge.sports_enabled'), true);
    }

    /** @return array<int, self> */
    public static function enabled(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $s) => $s->isEnabled()));
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $s) => $s->value, self::cases());
    }
}
