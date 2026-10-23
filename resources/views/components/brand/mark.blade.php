@props(['size' => 'h-8'])

{{--
    Inlined rather than loaded through <img>.

    The mark is drawn in currentColor so one file serves the dark field,
    the light field and a disabled state. An <img> is an isolated document
    and cannot see the colour of the page around it, so the same artwork in
    a src attribute renders black. Inline, it inherits.

    The prop is `size` and not `class`: `class` is already the attribute
    bag's own name, so a component declaring it as a prop silently loses
    whichever of the two the caller wrote second.

    public/images/brand/vigil-mark.svg remains the file of record for
    anything outside this app that needs a URL.
--}}
<svg {{ $attributes->merge(['class' => $size.' aspect-square']) }}
     viewBox="0 0 64 64" fill="none" role="img" aria-hidden="true" focusable="false">
    <path fill="currentColor" d="M6 7h14.6l11.4 32.1L43.4 7H58L36.9 60h-9.8L6 7Z"/>
    <path fill="currentColor" fill-opacity="0.55" d="M20.6 7 32 39.1 43.4 7l-6.1 14.9L32 24.6l-5.3-2.7L20.6 7Z"/>
</svg>
