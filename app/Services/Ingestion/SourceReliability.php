<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

/**
 * Honesty about where numbers come from. Anything not Official gets a label
 * in the UI so a quiet upstream change is never mistaken for signal.
 */
enum SourceReliability: string
{
    case Official = 'official';
    case DocumentedPublic = 'documented_public';
    case Community = 'community';
    case Unofficial = 'unofficial';

    public function label(): string
    {
        return match ($this) {
            self::Official => 'Official league API',
            self::DocumentedPublic => 'Documented public API',
            self::Community => 'Community dataset',
            self::Unofficial => 'Unofficial endpoint',
        };
    }

    public function needsWatching(): bool
    {
        return $this === self::Unofficial || $this === self::Community;
    }
}
