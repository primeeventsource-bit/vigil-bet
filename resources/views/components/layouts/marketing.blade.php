@props(['title' => null, 'description' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    {{-- Marketing pages are the only indexable ones. See partials/head. --}}
    @include('partials.head', [
        'title' => $title,
        'description' => $description,
        'indexable' => true,
    ])
</head>
<body class="min-h-screen bg-[var(--vg-night)] text-[var(--vg-paper)]">
    <a href="#main" class="skip-link">Skip to content</a>

    <x-marketing.nav />

    <main id="main">
        {{ $slot }}
    </main>

    @include('partials.footer')
</body>
</html>
