@php
    use App\Support\Clock;
@endphp

<x-layouts.app title="Odds comparison">
    <header>
        <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Odds comparison</h1>
        <p class="mt-2 max-w-3xl text-sm text-[var(--vg-slate)]">
            Every book's price for a game, side by side. Today's Games shows the best of them;
            this shows who is offering it.
        </p>
        @if ($pricedAt)
            <p class="mt-1 font-[family-name:var(--font-mono)] text-[11px] text-[var(--vg-muted)]">
                Newest price pulled {{ $pricedAt->diffForHumans() }}
            </p>
        @endif
    </header>

    @if ($sports->isNotEmpty())
        <form method="GET" action="{{ route('odds') }}" class="mt-5 flex flex-wrap items-end gap-3">
            <label class="block text-sm">
                <span class="text-[var(--vg-slate)]">League</span>
                <select name="sport" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                    <option value="">All</option>
                    @foreach ($sports as $key)
                        <option value="{{ $key }}" @selected($sport === $key)>{{ strtoupper($key) }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="rounded-lg border border-[var(--vg-line)] px-4 py-2 text-sm font-semibold">
                Filter
            </button>
        </form>
    @endif

    @if ($games->isEmpty())
        <section class="panel mt-5 p-8 text-center">
            <p class="font-semibold">No priced games ahead.</p>
            <p class="mt-1 mx-auto max-w-lg text-sm text-[var(--vg-muted)]">
                {{--
                    The honest reason, not a shrug. This limit is the
                    data licence working as intended rather than a
                    feature that has not been built.
                --}}
                Prices are only held for games somebody has already opened, and nothing
                inside the next three days has a quote fresh enough to stand behind.
            </p>
        </section>
    @else
        <section class="panel mt-5 overflow-hidden">
            <div class="w-full overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <caption class="sr-only">Upcoming games with prices</caption>
                    <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr class="border-b border-[var(--vg-line)]">
                            <th scope="col" class="px-5 py-2.5 font-medium">Game</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">League</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Starts</th>
                            <th scope="col" class="px-5 py-2.5 text-right font-medium">Markets</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($games as $game)
                            <tr class="border-b border-[var(--vg-line)] last:border-0">
                                <td class="px-5 py-3">
                                    <a class="font-medium underline hover:text-[var(--vg-paper)]"
                                       href="{{ route('odds.show', $game['id']) }}">
                                        {{ $game['away'] }} at {{ $game['home'] }}
                                    </a>
                                </td>
                                <td class="px-3 py-3 font-[family-name:var(--font-mono)] text-[11px] uppercase text-[var(--vg-slate)]">
                                    {{ $game['sport'] }}
                                </td>
                                <td class="px-3 py-3 text-xs text-[var(--vg-slate)]">
                                    {{ Clock::format($game['starts_at'], 'D j M, g:i A') }}
                                </td>
                                <td class="px-5 py-3 text-right font-[family-name:var(--font-mono)] text-xs text-[var(--vg-slate)]">
                                    {{ count($game['markets']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <p class="mt-4 text-[11px] text-[var(--vg-muted)]">
        Prices are what the books were posting when they were last pulled. Not a recommendation.
    </p>
</x-layouts.app>
