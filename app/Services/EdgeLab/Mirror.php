<?php

declare(strict_types=1);

namespace App\Services\EdgeLab;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Writes an Edge Lab row into a Vigil mirror, and only when it changed.
 *
 * The digest is what keeps these tables honest and small at the same
 * time. Without it an hourly sync against a quiet Tuesday would append a
 * fresh copy of the whole board every hour, and the history - which is the
 * entire reason the tables are append-only - would be almost all noise
 * with the real changes buried in it.
 *
 * The digest covers the payload Edge Lab sent, not the columns Vigil
 * happened to pull out of it. A change in a field Vigil does not yet read
 * is still a change, and the day Vigil starts reading that field the
 * history should already contain it.
 */
class Mirror
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $payload  the row exactly as Edge Lab sent it
     * @param  array<string, mixed>  $columns  the fields Vigil reads out of it
     * @return bool whether a row was written
     */
    public function write(string $model, array $payload, array $columns, ?Carbon $observedAt = null): bool
    {
        $digest = self::digest($payload);
        $edgeLabId = $columns['edge_lab_id'] ?? null;

        if ($edgeLabId !== null && $this->unchanged($model, (int) $edgeLabId, $digest)) {
            return false;
        }

        $model::create($columns + [
            'payload' => $payload,
            'digest' => $digest,
            'observed_at' => $observedAt ?? now(),
        ]);

        return true;
    }

    /**
     * Whether the newest row we hold for this record already says this.
     *
     * Compares against the newest row only, not against any row ever
     * seen: a value that changed and changed back is two real changes,
     * and collapsing them would lose the fact that it moved.
     *
     * @param  class-string<Model>  $model
     */
    private function unchanged(string $model, int $edgeLabId, string $digest): bool
    {
        $latest = $model::query()
            ->where('edge_lab_id', $edgeLabId)
            ->orderByDesc('id')
            ->value('digest');

        return $latest !== null && hash_equals((string) $latest, $digest);
    }

    /**
     * A stable fingerprint of a payload.
     *
     * Keys are sorted recursively first, because two JSON objects with the
     * same contents in a different order are the same fact, and an
     * upstream that reorders its keys must not read as a board full of
     * changes.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function digest(array $payload): string
    {
        return hash('sha256', (string) json_encode(self::sortDeep($payload)));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sortDeep(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortDeep($item);
            }
        }

        // Lists keep their order; it is meaningful. Objects do not.
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
