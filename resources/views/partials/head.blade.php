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

@vite(['resources/css/app.css', 'resources/js/app.js'])
