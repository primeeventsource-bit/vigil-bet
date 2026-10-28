@props(['class' => ''])

{{--
    An arena, drawn rather than photographed.

    The specification asks for original sports imagery and forbids league
    marks and identifiable players, which rules out stock photography
    almost by definition - a usable stadium photograph has a sponsor
    board in it, and a usable action shot has somebody's face.

    So this is geometry: a bowl seen in perspective, a pitch, four
    floodlights, and the measurement grid the product actually puts over
    a game. It is drawn entirely from palette tokens, so it repaints with
    the theme and cannot drift from the brand; it weighs nothing; and it
    owns no likeness of anybody.

    Decorative throughout. `aria-hidden`, and nothing here is load-
    bearing: the headline beside it says what the product is, and a
    screen reader does not need a stadium read out. Motion is left to
    the stylesheet's reduced-motion rules rather than animated here.
--}}
<svg {{ $attributes->merge(['class' => 'pointer-events-none absolute select-none '.$class]) }}
     aria-hidden="true"
     focusable="false"
     viewBox="0 0 640 420"
     fill="none"
     preserveAspectRatio="xMidYMax meet">
    <defs>
        {{-- The pitch glow: emerald, which is the token that may never carry text. --}}
        <radialGradient id="fx-arena-pitch" cx="50%" cy="62%" r="42%">
            <stop offset="0%" stop-color="var(--vg-emerald)" stop-opacity="0.55" />
            <stop offset="100%" stop-color="var(--vg-emerald)" stop-opacity="0" />
        </radialGradient>

        {{-- Stand tiers fade out toward the rim, so the bowl has depth without a horizon line. --}}
        <linearGradient id="fx-arena-stand" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="var(--vg-decor)" stop-opacity="0" />
            <stop offset="55%" stop-color="var(--vg-decor)" stop-opacity="0.34" />
            <stop offset="100%" stop-color="var(--vg-decor)" stop-opacity="0.08" />
        </linearGradient>

        <linearGradient id="fx-arena-beam" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="var(--vg-paper)" stop-opacity="0.16" />
            <stop offset="100%" stop-color="var(--vg-paper)" stop-opacity="0" />
        </linearGradient>
    </defs>

    {{-- Floodlight beams, behind everything, so the bowl sits inside the light. --}}
    <g>
        <path d="M96 42 L36 300 L188 300 Z" fill="url(#fx-arena-beam)" />
        <path d="M232 28 L196 300 L320 300 Z" fill="url(#fx-arena-beam)" />
        <path d="M408 28 L320 300 L444 300 Z" fill="url(#fx-arena-beam)" />
        <path d="M544 42 L452 300 L604 300 Z" fill="url(#fx-arena-beam)" />
    </g>

    {{-- The bowl: four tiers of stands in perspective. --}}
    <g stroke="url(#fx-arena-stand)" stroke-width="1.25">
        <ellipse cx="320" cy="262" rx="300" ry="138" />
        <ellipse cx="320" cy="262" rx="252" ry="114" />
        <ellipse cx="320" cy="262" rx="206" ry="92" />
        <ellipse cx="320" cy="262" rx="162" ry="71" />
    </g>

    {{--
        Radial gangways. Drawn as spokes between the outer rim and the
        inner tier, which is what makes the ellipses read as a seating
        bowl rather than as a target.
    --}}
    <g stroke="var(--vg-decor)" stroke-opacity="0.16" stroke-width="1">
        <path d="M320 124 L320 191" />
        <path d="M107 240 L163 253" />
        <path d="M533 240 L477 253" />
        <path d="M150 180 L196 213" />
        <path d="M490 180 L444 213" />
        <path d="M320 400 L320 333" />
        <path d="M150 344 L196 311" />
        <path d="M490 344 L444 311" />
    </g>

    {{-- The playing surface. --}}
    <ellipse cx="320" cy="262" rx="150" ry="65" fill="url(#fx-arena-pitch)" />

    <g stroke="var(--vg-green)" stroke-opacity="0.5" stroke-width="1.25">
        <ellipse cx="320" cy="262" rx="150" ry="65" />
        {{-- Halfway line and centre circle, which is every field sport at once and none in particular. --}}
        <path d="M320 197 L320 327" />
        <ellipse cx="320" cy="262" rx="34" ry="15" />
        {{-- The two ends. --}}
        <path d="M170 243 L206 243 L206 281 L170 281" />
        <path d="M470 243 L434 243 L434 281 L470 281" />
    </g>

    {{-- Floodlight masts. --}}
    <g fill="var(--vg-decor)" fill-opacity="0.4">
        <rect x="92" y="38" width="8" height="5" rx="1.5" />
        <rect x="228" y="24" width="8" height="5" rx="1.5" />
        <rect x="404" y="24" width="8" height="5" rx="1.5" />
        <rect x="540" y="38" width="8" height="5" rx="1.5" />
    </g>

    {{--
        The measurement over the top: the product's own trace, with no
        axis and no scale, the same way every other illustration on this
        page draws one. It is the point of the picture - a stadium on its
        own is a sports site, a stadium being measured is this one.
    --}}
    <g>
        <path d="M60 206 L148 184 L236 196 L324 150 L412 172 L500 124 L580 140"
              stroke="var(--vg-paper)" stroke-opacity="0.22" stroke-width="1.5"
              stroke-linecap="round" stroke-linejoin="round" />
        @foreach ([[148, 184], [324, 150], [500, 124]] as [$x, $y])
            <circle cx="{{ $x }}" cy="{{ $y }}" r="2.5"
                    fill="var(--vg-green)" fill-opacity="0.65" />
        @endforeach
    </g>
</svg>
