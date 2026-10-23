<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * writeAudit() on every administrative write.
 *
 * A convention rather than a nicety because the writes it covers -
 * granting a customer a feature they did not pay for, changing a tier by
 * hand, withdrawing something - are precisely the ones that become
 * indistinguishable from a bug a month later unless somebody recorded
 * that a person did them, and why.
 */
final class Audit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function write(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): AuditEvent {
        return AuditEvent::create([
            'actor_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject === null ? null : class_basename($subject),
            'subject_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => Request::ip(),
            'created_at' => now(),
        ]);
    }
}
