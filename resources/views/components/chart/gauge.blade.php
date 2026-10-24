@props(['value' => null, 'label' => 'Average', 'size' => 150])

@php
    /*
     * A single headline number, with the ring as its secondary encoding
     * rather than its only one. The figure is always printed in the
     * middle: a reader must never have to estimate an arc.
     */
    $pct = $value === null ? null : max(0.0, min(1.0, (float) $value));
    $r = 52;
    $circumference = 2 * M_PI * $r;
    $dash = $pct === null ? 0 : $circumference * $pct;
@endphp

<figure class="flex flex-col items-center">
    <div class="relative" style="width: {{ $size }}px; height: {{ $size }}px;">
        <svg viewBox="0 0 120 120" class="h-full w-full -rotate-90" role="img"
             aria-label="{{ $label }}: {{ $pct === null ? 'no data' : round($pct * 100).' percent' }}">
            <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="var(--vg-chart-grid)" stroke-width="10" />

            @if ($pct !== null)
                <circle cx="60" cy="60" r="{{ $r }}" fill="none"
                        stroke="var(--vg-chart-line)" stroke-width="10" stroke-linecap="round"
                        stroke-dasharray="{{ round($dash, 2) }} {{ round($circumference - $dash, 2) }}" />
            @endif
        </svg>

        <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
            <span class="font-[family-name:var(--font-mono)] text-2xl font-bold tabular-nums">
                {{ $pct === null ? '—' : round($pct * 100).'%' }}
            </span>
            <span class="mt-0.5 px-2 text-[11px] leading-tight text-[var(--vg-slate)]">{{ $label }}</span>
        </div>
    </div>
</figure>
