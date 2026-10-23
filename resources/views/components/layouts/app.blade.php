@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    {{--
        No indexable flag, so partials/head emits noindex. Everything
        behind a login stays out of search results.
    --}}
    @include('partials.head', ['title' => $title])
</head>
<body class="min-h-screen bg-[var(--vg-night)] text-[var(--vg-paper)]">
    <a href="#main" class="skip-link">Skip to content</a>

    <header class="border-b border-[var(--vg-line)] bg-[var(--vg-navy)]">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-3">
            <a href="{{ route('today') }}" aria-label="{{ config('brand.name') }} home">
                <x-brand.lockup size="h-7" />
            </a>

            <div class="flex items-center gap-4 text-sm">
                <a class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]" href="{{ route('account') }}">Account</a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-[var(--vg-slate)] hover:text-[var(--vg-paper)]">Sign out</button>
                </form>
            </div>
        </div>
    </header>

    @if (session('status') || session('error'))
        {{--
            Every action says whether it worked. A silent success and a
            silent failure look identical from the outside.
        --}}
        <div role="status" aria-live="polite" class="mx-auto mt-4 max-w-6xl px-6">
            <p @class([
                'rounded-md border px-4 py-2 text-sm',
                'border-[var(--vg-edge)] text-[var(--vg-edge)]' => session('status'),
                'border-[var(--vg-loss)] text-[var(--vg-loss)]' => session('error'),
            ])>{{ session('status') ?? session('error') }}</p>
        </div>
    @endif

    <main id="main" class="mx-auto max-w-6xl px-6 py-8">
        {{ $slot }}
    </main>

    @include('partials.footer')
</body>
</html>
