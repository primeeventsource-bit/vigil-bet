@props(['label', 'value' => null, 'max' => 1.0, 'suffix' => '%', 'format' => 'percent'])

{{--
    A labelled bar with its number beside it.

    `format` exists because this component printed every value as a
    percentage - `round($value * 100)` - which is right for a rate
    between zero and one and silently wrong for a count. Three callers
    were passing counts, so the board reported 1,769,200 candidates
    declined for no price out of 23,364 judged, and the admin overview
    multiplied its customers by a hundred.

    The suffix was already being set to an empty string at those call
    sites, which looked like the whole fix and was only half of it: it
    removed the per-cent sign and left the arithmetic behind it.
--}}
@php
    // The bar is a proportion of the row's own scale either way.
    $pct = $value === null ? null : max(0.0, min(1.0, (float) $value / max((float) $max, 0.0001)));

    $printed = match (true) {
        $value === null => '—',
        $format === 'count' => number_format((float) $value),
        default => round((float) $value * 100).$suffix,
    };
@endphp

<div class="flex items-center gap-3 text-sm">
    <span class="w-14 shrink-0 text-[var(--vg-slate)]">{{ $label }}</span>

    <div class="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-[var(--vg-line)]">
        @if ($pct !== null)
            <div class="h-full rounded-full bg-[var(--vg-chart-fill)]" style="width: {{ round($pct * 100, 1) }}%"></div>
        @endif
    </div>

    {{-- Wider than before: a count needs more room than "94%". --}}
    <span class="w-16 shrink-0 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
        {{ $printed }}
    </span>
</div>
