<x-layouts.app title="Today's games">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Today's games</h1>
            <p class="mt-1 max-w-2xl text-sm text-[var(--vg-slate)]">
                Every market the books are pricing. Click a selection to put it on your slip —
                these are your picks, independent of whether VIGIL recommends them.
            </p>
        </div>

        @if ($slipCount > 0)
            <a href="{{ route('parlays') }}"
               class="rounded-lg bg-[var(--vg-paper)] px-4 py-2.5 text-sm font-bold text-[var(--vg-night)]">
                Slip ({{ $slipCount }})
            </a>
        @endif
    </div>

    @error('slip')
        <p class="mt-4 rounded-lg border border-[var(--vg-loss)] px-4 py-3 text-sm">{{ $message }}</p>
    @enderror

    @if ($sports->isNotEmpty())
        <nav class="mt-5 flex flex-wrap gap-2" aria-label="Filter by sport">
            <a href="{{ route('games') }}"
               @class([
                   'rounded-full border px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wide',
                   'border-[var(--vg-paper)] bg-[var(--vg-paper)] text-[var(--vg-night)]' => $sport === null,
                   'border-[var(--vg-line)] text-[var(--vg-slate)]' => $sport !== null,
               ])>All</a>

            @foreach ($sports as $option)
                <a href="{{ route('games', ['sport' => $option]) }}"
                   @class([
                       'rounded-full border px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wide',
                       'border-[var(--vg-paper)] bg-[var(--vg-paper)] text-[var(--vg-night)]' => $sport === $option,
                       'border-[var(--vg-line)] text-[var(--vg-slate)]' => $sport !== $option,
                   ])>{{ $option }}</a>
            @endforeach
        </nav>
    @endif

    @if ($games->isEmpty())
        {{--
            Said precisely. "No games" and "no prices for the games we
            have" are different facts, and a customer who is told the
            wrong one goes looking in the wrong place.
        --}}
        <div class="panel mt-6 px-5 py-12 text-center">
            <p class="text-sm text-[var(--vg-slate)]">
                No priced markets in the next three days. Games appear here once a sportsbook
                is quoting them — this is not a judgement about the games, only about what the
                books have published.
            </p>
        </div>
    @else
        <div class="mt-6 space-y-4">
            @foreach ($games as $game)
                <section class="panel overflow-hidden">
                    <header class="flex flex-wrap items-baseline justify-between gap-2 border-b border-[var(--vg-line)] px-5 py-3.5">
                        <h2 class="font-[family-name:var(--font-display)] font-semibold">
                            {{ $game['away'] }} <span class="text-[var(--vg-slate)]">at</span> {{ $game['home'] }}
                        </h2>
                        <p class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                            {{ $game['sport'] }} &middot;
                            <time datetime="{{ $game['starts_at']->toIso8601String() }}">
                                {{ $game['starts_at']->format('D j M, H:i') }}
                            </time>
                        </p>
                    </header>

                    @foreach (['h2h' => 'Moneyline', 'spreads' => 'Spread', 'totals' => 'Total'] as $market => $heading)
                        @continue(empty($game['markets'][$market]))

                        <div class="border-b border-[var(--vg-line)] px-5 py-3 last:border-b-0">
                            <p class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">{{ $heading }}</p>

                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach ($game['markets'][$market] as $option)
                                    @php($taken = in_array($option['key'], $onSlip, true))

                                    {{--
                                        A form per selection rather than one
                                        handler over the page. It works
                                        without JavaScript, every button is a
                                        real button, and there is no overlay
                                        between the finger and the target -
                                        which is the class of bug that makes
                                        a page look dead.

                                        The posted fields identify the
                                        selection and never carry its price:
                                        the server reads that back from its
                                        own snapshot table, because a form
                                        field is something the customer
                                        controls.
                                    --}}
                                    <form method="POST"
                                          action="{{ $taken ? route('parlays.remove') : route('parlays.add') }}">
                                        @csrf
                                        @if ($taken)
                                            @method('DELETE')
                                            <input type="hidden" name="key" value="{{ $option['key'] }}">
                                        @else
                                            <input type="hidden" name="event_id" value="{{ $option['event_id'] }}">
                                            <input type="hidden" name="market" value="{{ $option['market'] }}">
                                            <input type="hidden" name="side" value="{{ $option['side'] }}">
                                            <input type="hidden" name="line" value="{{ $option['line'] }}">
                                        @endif

                                        <button type="submit"
                                                aria-pressed="{{ $taken ? 'true' : 'false' }}"
                                                @class([
                                                    'flex w-full items-center justify-between gap-3 rounded-lg border px-4 py-3 text-left transition',
                                                    'min-h-12' => true,
                                                    'border-[var(--vg-paper)] bg-[var(--vg-slate-dark)]' => $taken,
                                                    'border-[var(--vg-line)] hover:border-[var(--vg-paper)]' => ! $taken,
                                                ])>
                                            <span class="min-w-0">
                                                <span class="block truncate text-sm font-medium">
                                                    {{ $market === 'totals'
                                                        ? ucfirst($option['side']).' '.rtrim(rtrim(number_format((float) $option['line'], 1, '.', ''), '0'), '.')
                                                        : ($market === 'spreads'
                                                            ? ($option['side'] === 'home' ? $game['home'] : $game['away']).' '.($option['line'] > 0 ? '+' : '').rtrim(rtrim(number_format((float) $option['line'], 1, '.', ''), '0'), '.')
                                                            : ($option['side'] === 'home' ? $game['home'] : $game['away'])) }}
                                                </span>
                                                <span class="mt-0.5 block text-xs text-[var(--vg-slate)]">
                                                    {{ $option['book_title'] }}
                                                    @if ($option['books'] > 1)
                                                        &middot; best of {{ $option['books'] }}
                                                    @endif
                                                </span>
                                            </span>

                                            <span class="shrink-0 font-[family-name:var(--font-mono)] font-bold tabular-nums">
                                                {{ $option['price_american'] > 0 ? '+' : '' }}{{ $option['price_american'] }}
                                            </span>
                                        </button>
                                    </form>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </section>
            @endforeach
        </div>
    @endif

    {{--
        The floating slip indicator, for a phone. The desktop link lives
        in the header above; on a narrow screen that scrolls away, and a
        slip you cannot get back to is a slip you lose.
    --}}
    @if ($slipCount > 0)
        <a href="{{ route('parlays') }}"
           class="fixed bottom-5 left-1/2 z-40 -translate-x-1/2 rounded-full bg-[var(--vg-paper)] px-5 py-3 text-sm font-bold text-[var(--vg-night)] shadow-lg sm:hidden">
            {{ $slipCount }} of {{ $maxLegs }} on slip &middot; open builder
        </a>
    @endif
</x-layouts.app>
