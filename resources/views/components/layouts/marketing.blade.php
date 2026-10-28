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

    <header class="border-b border-[var(--vg-line)]">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
            <a href="{{ route('home') }}" aria-label="{{ config('brand.name') }} home">
                <x-brand.lockup size="h-8" />
            </a>

            <nav aria-label="Main" class="hidden items-center gap-6 text-sm sm:flex">
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('features') }}">Features</a>
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('how-it-works') }}">How it works</a>
                {{-- The page 404s while nothing has passed the gate, so the nav goes with it. --}}
                {{-- Linked when the page exists, which is now also the
                     case when picks were published under an earlier
                     standard. The page says so itself, at the top. --}}
                @if (\App\Models\VlModel::anyMarketProven() || \App\Models\PublishedPick::anyPublished())
                    <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('record') }}">Track record</a>
                @endif
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('pricing') }}">Pricing</a>
            </nav>

            <div class="flex items-center gap-3 text-sm">
                {{-- Before sign-in as well as after: the choice should not need an account. --}}
                <x-theme-toggle />

                @auth
                    <a class="rounded-md bg-[var(--vg-paper)] px-3 py-1.5 font-semibold text-[var(--vg-night)]"
                       href="{{ route('today') }}">Open {{ config('brand.name') }}</a>
                @else
                    <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('login') }}">Sign in</a>
                    <a class="rounded-md bg-[var(--vg-paper)] px-3 py-1.5 font-semibold text-[var(--vg-night)]"
                       href="{{ route('register') }}">Get started</a>
                @endauth
            </div>
        </div>
    </header>

    <main id="main">
        {{ $slot }}
    </main>

    @include('partials.footer')
</body>
</html>
