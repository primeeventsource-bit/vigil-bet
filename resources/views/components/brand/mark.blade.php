@props(['size' => 'h-8'])

{{--
    The eagle/V mark, drawn as a mask rather than an image.

    The artwork is a raster extraction from the supplied reference - the
    asset notes are explicit that no vector exists in that file, and
    nothing here pretends to one. But an <img> is an isolated document
    and cannot see the colour of the page around it, so the mark would
    render at whatever colour it was cut at and the light theme would
    show white on white.

    Masking solves both. The PNG supplies the shape and its antialiased
    edge; `currentColor` supplies the colour, so one file serves the dark
    field, the light field and a disabled state exactly as the old inline
    SVG did.

    The prop is `size` and not `class`: `class` is already the attribute
    bag's own name, so a component declaring it as a prop silently loses
    whichever of the two the caller wrote second.
--}}
@php
    // 196x165 as extracted, so the box is kept at that ratio rather than
    // forced square - squaring it would letterbox the mark and shrink it
    // against everything set beside it.
    $mark = asset('images/brand/vigil-mark.png');
@endphp

<span
    {{ $attributes->merge(['class' => $size]) }}
    role="img"
    aria-hidden="true"
    style="
        aspect-ratio: 196 / 165;
        display: inline-block;
        background-color: currentColor;
        -webkit-mask-image: url('{{ $mark }}');
        mask-image: url('{{ $mark }}');
        -webkit-mask-repeat: no-repeat;
        mask-repeat: no-repeat;
        -webkit-mask-position: center;
        mask-position: center;
        -webkit-mask-size: contain;
        mask-size: contain;
    "></span>
