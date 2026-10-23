<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a customer asked to be told about.
 *
 * Both channels default to off. An account that began emailing somebody
 * because they signed up would be sending unsolicited mail whatever the
 * footer said, and §9's compliance section is not a footer.
 */
class AlertPreference extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'email_enabled' => 'boolean',
            'push_enabled' => 'boolean',
            'on_new_pick' => 'boolean',
            'on_line_move' => 'boolean',
            'sports' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether this customer wants to hear about this sport. */
    public function coversSport(?string $sport): bool
    {
        $sports = $this->sports;

        // Empty means every sport, which is the default and the sensible
        // reading of "I did not narrow this".
        return $sports === null || $sports === [] || ($sport !== null && in_array($sport, $sports, true));
    }

    /**
     * Whether now is inside the hours this customer asked to be left
     * alone.
     *
     * Computed in their own timezone, because a quiet window that fires
     * at 3am for somebody three zones over is worse than no window.
     */
    public function isQuietAt(?Carbon $at = null): bool
    {
        if ($this->quiet_from_hour === null || $this->quiet_to_hour === null) {
            return false;
        }

        $hour = (int) ($at ?? now())->setTimezone($this->timezone ?: 'UTC')->hour;

        $from = (int) $this->quiet_from_hour;
        $to = (int) $this->quiet_to_hour;

        // A window that crosses midnight - 22 to 07, the usual case -
        // reads as two ranges rather than one.
        return $from <= $to
            ? $hour >= $from && $hour < $to
            : $hour >= $from || $hour < $to;
    }

    /** @return array<int, string> */
    public function channels(): array
    {
        return array_values(array_filter([
            $this->email_enabled ? 'email' : null,
            $this->push_enabled ? 'push' : null,
        ]));
    }
}
