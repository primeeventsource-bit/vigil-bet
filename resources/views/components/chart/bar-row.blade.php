@props(['label', 'value' => null, 'max' => 1.0, 'suffix' => '%'])

@php
    // Magnitude, one hue. The number is printed beside the bar, so the
    // bar is a comparison aid rather than the only way to read a value.
    $pct = $value === null ? null : max(0.0, min(1.0, (float) $value / max((float) $max, 0.0001)));
@endphp

<div class="flex items-center gap-3 text-sm">
    <span class="w-14 shrink-0 text-[var(--vg-slate)]">{{ $label }}</span>

    <div class="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-[var(--vg-line)]">
        @if ($pct !== null)
            <div class="h-full rounded-full bg-[var(--vg-chart-fill)]" style="width: {{ round($pct * 100, 1) }}%"></div>
        @endif
    </div>

    <span class="w-11 shrink-0 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
        {{ $value === null ? '—' : round((float) $value * 100).$suffix }}
    </span>
</div>
