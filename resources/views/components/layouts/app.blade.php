@props(['title' => null, 'heading' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    {{-- No indexable flag, so partials/head emits noindex. --}}
    @include('partials.head', ['title' => $title])
</head>
<body class="min-h-screen bg-[var(--vg-night)] text-[var(--vg-paper)]">
    <a href="#main" class="skip-link">Skip to content</a>

    <div class="flex min-h-screen">
        <x-app.sidebar />

        <div class="flex min-w-0 flex-1 flex-col">
            <x-app.topbar />

            @if (session('status') || session('error'))
                {{-- Every action says whether it worked. --}}
                <div role="status" aria-live="polite" class="px-6 pt-4">
                    <p @class([
                        'rounded-md border px-4 py-2 text-sm',
                        'border-[var(--vg-edge)] text-[var(--vg-edge)]' => session('status'),
                        'border-[var(--vg-loss)] text-[var(--vg-loss)]' => session('error'),
                    ])>{{ session('status') ?? session('error') }}</p>
                </div>
            @endif

            <main id="main" class="min-w-0 max-w-full flex-1 overflow-x-hidden px-4 py-6 sm:px-6">
                {{ $slot }}
            </main>

            <footer class="border-t border-[var(--vg-line)] px-6 py-4 text-xs text-[var(--vg-slate)]">
                <p class="font-semibold text-[var(--vg-paper)]">
                    {{ config('vigil.responsible_gambling_line') }}
                </p>
                <p class="mt-1">
                    {{ config('brand.name') }} sells information and tools. It never takes a bet or
                    holds your money. {{ config('vigil.minimum_age') }}+ only.
                    <a class="underline" href="{{ route('legal.responsible-gambling') }}">Responsible gambling</a>
                </p>
            </footer>
        </div>
    </div>
</body>
</html>
