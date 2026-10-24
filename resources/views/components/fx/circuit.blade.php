{{--
    Decoration, and only decoration.

    An abstract rising trace behind the hero. It is marked aria-hidden and
    carries no axis, no scale and no numbers, precisely so it cannot be
    read as a track record - a marketing page showing a chart that looks
    like results, before there are any results, is the exact thing §0.7
    rules out. It is a texture, drawn to look like one.
--}}
<svg {{ $attributes->merge(['class' => 'pointer-events-none absolute inset-0 h-full w-full']) }}
     viewBox="0 0 1200 600" preserveAspectRatio="xMidYMid slice"
     aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="fx-trace" x1="0" y1="1" x2="1" y2="0">
            <stop offset="0%" stop-color="var(--vg-decor)" stop-opacity="0" />
            <stop offset="45%" stop-color="var(--vg-decor)" stop-opacity="0.55" />
            <stop offset="100%" stop-color="var(--vg-decor)" stop-opacity="0.95" />
        </linearGradient>

        <linearGradient id="fx-trace-soft" x1="0" y1="1" x2="1" y2="0">
            <stop offset="0%" stop-color="var(--vg-decor)" stop-opacity="0" />
            <stop offset="100%" stop-color="var(--vg-decor)" stop-opacity="0.4" />
        </linearGradient>

        <filter id="fx-bloom" x="-20%" y="-20%" width="140%" height="140%">
            <feGaussianBlur stdDeviation="7" result="b" />
            <feMerge>
                <feMergeNode in="b" />
                <feMergeNode in="SourceGraphic" />
            </feMerge>
        </filter>
    </defs>

    {{-- A second, cooler trace behind, for depth. --}}
    <path d="M-40 500 L120 470 L210 495 L320 410 L430 440 L540 350 L660 395 L780 300 L900 330 L1020 240 L1240 280"
          fill="none" stroke="url(#fx-trace-soft)" stroke-width="2" opacity="0.5" />

    <path d="M-40 540 L140 505 L240 530 L350 430 L470 465 L580 360 L700 400 L820 285 L940 320 L1060 200 L1240 250"
          fill="none" stroke="url(#fx-trace)" stroke-width="2.5"
          stroke-linecap="round" stroke-linejoin="round" filter="url(#fx-bloom)" />

    {{-- Nodes along the trace. Decorative; they mark nothing. --}}
    @foreach ([[350, 430], [580, 360], [820, 285], [1060, 200]] as [$cx, $cy])
        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="3.5" fill="var(--vg-decor)" filter="url(#fx-bloom)" opacity="0.9" />
    @endforeach
</svg>
