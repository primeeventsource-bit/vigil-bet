@props(['name', 'class' => 'h-4 w-4'])

@php
    /*
     * Line icons as path data, drawn in currentColor so they inherit the
     * state of whatever they sit in. Inline rather than a sprite or an
     * icon font: eight icons is not worth a dependency, and an <img> would
     * not be able to see the colour of the row around it.
     */
    $paths = [
        'grid' => 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
        'activity' => 'M22 12h-4l-3 9L9 3l-3 9H2',
        'target' => 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 17a5 5 0 100-10 5 5 0 000 10zM12 13a1 1 0 100-2 1 1 0 000 2z',
        'layers' => 'M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
        'chart' => 'M3 3v18h18M7 15l3-4 3 3 5-7',
        'cpu' => 'M6 6h12v12H6zM9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4',
        'file' => 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8zM14 2v6h6M9 13h6M9 17h6',
        'cog' => 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09A1.65 1.65 0 008 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 8a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z',
        'bell' => 'M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0',
        'search' => 'M11 19a8 8 0 100-16 8 8 0 000 16zM21 21l-4.35-4.35',
        'up' => 'M12 19V5M5 12l7-7 7 7',
        'down' => 'M12 5v14M19 12l-7 7-7-7',
        'plus' => 'M12 5v14M5 12h14',
        'close' => 'M18 6L6 18M6 6l12 12',
        'menu' => 'M4 6h16M4 12h16M4 18h16',
        'chevron' => 'M6 9l6 6 6-6',
        'lock' => 'M5 11h14v10H5zM8 11V7a4 4 0 018 0v4',
        'sun' => 'M12 17a5 5 0 100-10 5 5 0 000 10zM12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4',
        'moon' => 'M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z',
    ];
@endphp

<svg {{ $attributes->merge(['class' => $class]) }}
     viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true" focusable="false">
    <path d="{{ $paths[$name] ?? $paths['grid'] }}" />
</svg>
