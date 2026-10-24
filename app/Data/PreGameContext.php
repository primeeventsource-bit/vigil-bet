<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Sport;
use Carbon\CarbonImmutable;

/**
 * Everything a model is allowed to know before kickoff - and nothing else.
 *
 * There is deliberately no score on this object. The backtest hands the
 * predictor one of these rather than the game row, so a model physically
 * cannot read the result it is being asked to predict. A leakage test can be
 * fooled by a subtle bug; a type that does not carry the field cannot.
 */
final readonly class PreGameContext
{
    public function __construct(
        public int $eventId,
        public Sport $sport,
        public int $homeTeamId,
        public int $awayTeamId,
        public CarbonImmutable $startsAt,
        public bool $neutralSite = false,
        public ?int $season = null,
        // Schedule facts. Every one of these is published with the schedule,
        // days or weeks ahead of kickoff, so a model may use them freely.
        public ?int $homeRestDays = null,
        public ?int $awayRestDays = null,
        public ?string $roof = null,
        public ?string $surface = null,
        public ?bool $divisional = null,
        /** @var array<string, float> features built strictly from earlier games */
        public array $features = [],
    ) {}
}
