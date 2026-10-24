<?php

declare(strict_types=1);

namespace App\Enums;

enum InjuryStatus: string
{
    case Out = 'out';
    case Doubtful = 'doubtful';
    case Questionable = 'questionable';
    case Probable = 'probable';
    case Active = 'active';

    /**
     * A label the source used that this app does not recognise.
     *
     * Kept as a case rather than guessed at. Every sport publishes its own
     * vocabulary and they change it without telling anyone, so an unmapped
     * label is a fact about our coverage and belongs in the data. Mapping
     * it to Active would quietly declare a possibly-absent player fit;
     * mapping it to Out would bench a possibly-fit one. Both are worse than
     * saying we do not know.
     */
    case Unknown = 'unknown';

    /**
     * Rough availability weight for use as a model feature.
     *
     * Null for Unknown, deliberately, so a caller has to decide what to do
     * about not knowing rather than receiving a number that looks like an
     * answer. Features built on this treat null as missing data and drop
     * the feature for that game, the same way an absent rest day does.
     */
    public function availability(): ?float
    {
        return match ($this) {
            self::Out => 0.0,
            self::Doubtful => 0.25,
            self::Questionable => 0.5,
            self::Probable => 0.9,
            self::Active => 1.0,
            self::Unknown => null,
        };
    }

    public function isUnavailable(): bool
    {
        return $this === self::Out;
    }

    public function label(): string
    {
        return match ($this) {
            self::Out => 'Out',
            self::Doubtful => 'Doubtful',
            self::Questionable => 'Questionable',
            self::Probable => 'Probable',
            self::Active => 'Active',
            self::Unknown => 'Unrecognised status',
        };
    }

    /**
     * Map a source's own wording onto these cases.
     *
     * The list is built from what the feeds actually publish, read off live
     * responses for all six sports rather than guessed: the NFL says
     * Questionable, Doubtful, Out, Active, Injured Reserve and Suspension;
     * the NBA says Out and Day-To-Day; the NHL adds Injured Reserve; and
     * MLB has a whole family of injured lists - 7, 10, 15 and 60 day -
     * alongside suspension and paternity leave.
     *
     * Everything that keeps a player off the field maps to Out, including
     * suspension and paternity, because the feature being built is
     * availability and the reason does not change the availability. Nothing
     * unrecognised is guessed at.
     */
    public static function fromSourceLabel(?string $label): self
    {
        $normalized = mb_strtolower(trim((string) $label));

        if ($normalized === '') {
            return self::Unknown;
        }

        // Any flavour of injured list is simply unavailable.
        if (preg_match('/\b\d+\s*-?\s*day\b.*\bil\b/', $normalized) === 1 || $normalized === 'il') {
            return self::Out;
        }

        return match ($normalized) {
            'out', 'injured reserve', 'ir', 'suspension', 'suspended',
            'paternity', 'personal', 'not with team' => self::Out,
            'doubtful' => self::Doubtful,
            'questionable', 'day-to-day', 'day to day', 'gtd',
            'game-time decision' => self::Questionable,
            'probable' => self::Probable,
            'active', 'available', 'healthy' => self::Active,
            default => self::Unknown,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $s) => $s->value, self::cases());
    }
}
