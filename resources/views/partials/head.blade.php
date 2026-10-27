@props([
    'title' => null,
    'description' => null,
    'indexable' => false,
])

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>{{ $title ? $title.' — '.config('brand.name') : config('brand.name').' — '.config('brand.tagline') }}</title>

<meta name="description" content="{{ $description ?? config('brand.description') }}">

{{--
    App pages are noindex, marketing pages are not. Default to noindex: a
    page that leaks because somebody forgot a flag is a worse failure than
    a marketing page that takes one extra line to get indexed.
--}}
@unless ($indexable)
    <meta name="robots" content="noindex, nofollow">
@endunless

{{--
    One address per page, whichever host asked.

    Built from APP_URL rather than from the request, and that is the
    whole point of it. The application answers on more than one domain,
    and a canonical built from the request host would simply agree with
    whichever name the page was fetched under - confirming every
    duplicate instead of resolving it. Pinned to the configured host, the
    day APP_URL changes is the day every indexable page starts pointing
    at the new one, with nothing else to remember.

    Only on indexable pages. Everything behind the login is noindex
    already, and a canonical on a page no crawler may read says nothing.
--}}
@if ($indexable)
    @php($canonical = rtrim((string) config('app.url'), '/').(trim(request()->path(), '/') === '' ? '/' : '/'.trim(request()->path(), '/')))

    <link rel="canonical" href="{{ $canonical }}">
    <meta property="og:url" content="{{ $canonical }}">
@endif

<link rel="icon" href="{{ asset(config('brand.logo.favicon')) }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset(config('brand.logo.favicon')) }}">

<meta property="og:site_name" content="{{ config('brand.name') }}">
<meta property="og:title" content="{{ $title ?? config('brand.name') }}">
<meta property="og:description" content="{{ $description ?? config('brand.description') }}">
<meta property="og:type" content="website">
<meta name="twitter:card" content="summary_large_image">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|sora:600,700|jetbrains-mono:400,500&display=swap" rel="stylesheet">

{{--
    The palette, written once from config('brand.colors'). Nothing in
    resources/ may hardcode a hex value, so this is the only place colour
    crosses from PHP into CSS.
--}}
<style>
    :root {
        @foreach (config('brand.colors') as $token => $hex)
            --vg-{{ str_replace('_', '-', $token) }}: {{ $hex }};
        @endforeach
    }
</style>

{{--
    The theme, applied before the first paint.

    This has to be inline and it has to be here, above the stylesheet. The
    alternative - setting the attribute from app.js - leaves a visible
    flash of the wrong theme on every page load, because the browser has
    already painted a black page by the time a deferred module runs. It is
    the one place a script belongs in the head.

    Wrapped in try/catch because localStorage throws rather than returning
    null in a private window with site data blocked, and a theme
    preference is not worth a blank page.
--}}
<script>
    try {
        var t = localStorage.getItem('vigil.theme');
        if (t === 'light' || t === 'dark') {
            document.documentElement.setAttribute('data-theme', t);
        }
    } catch (e) {}
</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
