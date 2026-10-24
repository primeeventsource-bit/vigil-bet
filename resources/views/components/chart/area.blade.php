@props([
    'points' => [],
    'labels' => [],
    'height' => 180,
    'label' => 'Value over time',
])

@php
    /*
     * One series, so no legend: the panel title names it. The geometry is
     * computed here rather than in a library - a line, an axis and a fill
     * are about twenty lines of arithmetic, and a charting dependency in
     * a page this simple costs more than it saves.
     *
     * Values are plotted on a zero-anchored scale only when the data
     * genuinely starts near zero; a bankroll that moved from 10,000 to
     * 12,450 on a zero axis is a flat line, and on a tight axis it is the
     * truth. The floor is padded instead, and the axis is labelled, so
     * nobody reads the slope as bigger than it is.
     */
    $values = array_values(array_map('floatval', $points));
    $n = count($values);
    $w = 600;
    $h = (int) $height;
    $pad = 6;

    $min = $n ? min($values) : 0.0;
    $max = $n ? max($values) : 1.0;
    $span = max($max - $min, 0.0001);
    $floor = $min - $span * 0.15;
    $ceil = $max + $span * 0.10;
    $range = max($ceil - $floor, 0.0001);

    $x = fn (int $i) => $n < 2 ? $w / 2 : $pad + ($i * (($w - $pad * 2) / ($n - 1)));
    $y = fn (float $v) => $h - $pad - (($v - $floor) / $range) * ($h - $pad * 2);

    $line = '';
    foreach ($values as $i => $v) {
        $line .= ($i === 0 ? 'M' : 'L').round($x($i), 2).' '.round($y($v), 2).' ';
    }

    $fill = $n ? $line.'L'.round($x($n - 1), 2).' '.($h - $pad).' L'.round($x(0), 2).' '.($h - $pad).' Z' : '';
    $gradientId = 'vg-area-'.substr(md5($label.$n), 0, 8);
@endphp

@if ($n === 0)
    <p class="py-10 text-center text-sm text-[var(--vg-slate)]">No history yet.</p>
@else
    <figure class="mt-2">
        <svg viewBox="0 0 {{ $w }} {{ $h }}" class="w-full" role="img"
             aria-label="{{ $label }}: {{ $n }} points, from {{ number_format($values[0], 2) }} to {{ number_format(end($values), 2) }}"
             preserveAspectRatio="none">
            <defs>
                <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="var(--vg-chart-fill)" stop-opacity="0.28" />
                    <stop offset="100%" stop-color="var(--vg-chart-fill)" stop-opacity="0" />
                </linearGradient>
            </defs>

            {{-- Recessive grid: present enough to read against, never competing with the mark. --}}
            @foreach ([0.25, 0.5, 0.75] as $frac)
                <line x1="0" y1="{{ round($h * $frac, 1) }}" x2="{{ $w }}" y2="{{ round($h * $frac, 1) }}"
                      stroke="var(--vg-chart-grid)" stroke-width="1" opacity="0.5" />
            @endforeach

            <path d="{{ $fill }}" fill="url(#{{ $gradientId }})" />
            <path d="{{ $line }}" fill="none" stroke="var(--vg-chart-line)" stroke-width="2"
                  stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />

            {{-- The last point only. A dot on every point is noise. --}}
            <circle cx="{{ round($x($n - 1), 2) }}" cy="{{ round($y(end($values)), 2) }}" r="4"
                    fill="var(--vg-chart-line)" stroke="var(--vg-navy)" stroke-width="2" />
        </svg>

        @if ($labels)
            <figcaption class="mt-2 flex justify-between text-[11px] text-[var(--vg-slate)]" data-numeric>
                @foreach ($labels as $tick)
                    <span>{{ $tick }}</span>
                @endforeach
            </figcaption>
        @endif
    </figure>
@endif
