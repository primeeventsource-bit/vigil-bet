@props(['games', 'leagues', 'opinions', 'pricedAt' => null])

@php
    use App\Support\Clock;
    use Illuminate\Support\Str;

    /*
     * The three markets, in the order a bettor reads them. Named here so
     * the card below is a loop rather than three near-identical blocks
     * that drift apart.
     */
    $american = fn (int $price): string => ($price > 0 ? '+' : '').$price;
@endphp

{{--
    The board: today's games, filterable, at real prices.

    Everything on it comes out of the database. No invented fixture, no
    invented price, no invented confidence figure - and that last one is
    the reason the "AI evaluation" column says only whether a call exists
    rather than how sure it is. The engine passes on most games, those
    passes are the product's actual claim, and a confidence percentage
    beside every fixture would mean fabricating one for all of them.

    The filter is progressive enhancement, the same bargain the nav and
    the theme toggle make: the chips ship hidden, resources/js/board.js
    reveals them, and with scripting off every game is simply visible -
    which is the correct unfiltered state rather than a broken control.

    Nothing is clickable through to a members-only page from here. A game
    card that bounced a visitor to a login screen would spend the click
    on nothing, which is the mistake the feature ribbon above deliberately
    avoids making.
--}}
<section id="board"
         class="relative isolate scroll-mt-20 overflow-hidden border-b border-[var(--vg-line)]">
    <x-fx.section-glow class="right-0 top-0 h-72 w-[38rem] translate-x-1/4 -translate-y-1/2 opacity-25" />

    <div class="relative mx-auto max-w-7xl px-6 py-20">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="font-[family-name:var(--font-display)] text-3xl font-bold sm:text-4xl">
                    Command center
                </h2>
                <div class="fx-rule mt-5 max-w-xs"></div>
                <p class="mt-5 max-w-2xl text-[var(--vg-slate)]">
                    Every upcoming game we hold a price for, at the best number across the books we
                    track. Open to everyone, no account needed.
                </p>
            </div>

            @if ($pricedAt !== null)
                <p class="font-[family-name:var(--font-mono)] text-[11px] text-[var(--vg-muted)]">
                    Prices pulled {{ $pricedAt->diffForHumans() }}
                </p>
            @endif
        </div>

        @if ($games->isEmpty())
            {{--
                The empty state, which is a real state rather than a
                failure: outside a season, or in the small hours between
                slates, there is genuinely nothing within the window.

                It says which window, because "no games" without one
                reads as the feed being down.
            --}}
            <div class="fx-panel mt-10 px-6 py-16 text-center">
                <p class="font-[family-name:var(--font-display)] text-lg font-semibold">
                    No games on the board right now
                </p>
                <p class="mx-auto mt-3 max-w-md text-sm leading-relaxed text-[var(--vg-slate)]">
                    Nothing starts in the next 36 hours that we hold a current price for. The board
                    fills again as the books post the next slate.
                </p>
                <a href="{{ route('opinions') }}"
                   class="mt-7 inline-flex items-center gap-1.5 rounded-lg border border-[var(--vg-line)] px-5 py-2.5 text-sm font-semibold text-[var(--vg-paper)] transition hover:border-[color-mix(in_srgb,var(--vg-decor)_45%,transparent)]">
                    See the published record
                    <span aria-hidden="true">&rarr;</span>
                </a>
            </div>
        @else
            {{--
                The filter row. `role="group"` rather than a tablist:
                these filter a list that is already on the page, they do
                not switch between panels, and announcing them as tabs
                would promise a keyboard behaviour they do not have.
            --}}
            @if ($leagues->count() > 1)
                <div data-board-filters
                     hidden
                     role="group"
                     aria-label="Filter the board by league"
                     class="mt-9 flex flex-wrap items-center gap-2">
                    {{--
                        Pressed is a solid fill, not a tint.

                        It was green text on a 14% green wash, which
                        measured 4.12:1 on the light page at 11px - the
                        tint lifts the background toward the text and
                        takes the contrast with it. Solid green carrying
                        night text is the same pairing every primary
                        button on the site uses, and is verified at
                        4.98:1 light and 8.94:1 dark.
                    --}}
                    <button type="button"
                            data-board-filter="all"
                            aria-pressed="true"
                            class="rounded-full border border-[var(--vg-green)] bg-[var(--vg-green)] px-4 py-1.5 font-[family-name:var(--font-mono)] text-[11px] font-bold uppercase tracking-wider text-[var(--vg-night)] transition aria-[pressed=false]:border-[var(--vg-line)] aria-[pressed=false]:bg-transparent aria-[pressed=false]:text-[var(--vg-slate)]">
                        All
                        <span class="sr-only">leagues</span>
                    </button>

                    @foreach ($leagues as $league)
                        <button type="button"
                                data-board-filter="{{ $league }}"
                                aria-pressed="false"
                                class="rounded-full border border-[var(--vg-green)] bg-[var(--vg-green)] px-4 py-1.5 font-[family-name:var(--font-mono)] text-[11px] font-bold uppercase tracking-wider text-[var(--vg-night)] transition aria-[pressed=false]:border-[var(--vg-line)] aria-[pressed=false]:bg-transparent aria-[pressed=false]:text-[var(--vg-slate)]">
                            {{ strtoupper($league) }}
                        </button>
                    @endforeach
                </div>
            @endif

            {{--
                Announced to a screen reader when the count changes, so
                pressing a filter says what it did. Empty until the
                script has something to report.
            --}}
            <p data-board-status role="status" aria-live="polite" class="sr-only"></p>

            <ul role="list" data-board-list class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($games as $game)
                    @php
                        $h2h = collect($game['markets']['h2h'] ?? [])->keyBy('side');
                        $spread = collect($game['markets']['spreads'] ?? [])->keyBy('side');
                        $total = collect($game['markets']['totals'] ?? [])->keyBy('side');
                        $opinion = $opinions->get($game['id']);

                        /*
                         * How many books quote the moneyline at the
                         * consensus line. The most any one side has,
                         * not a sum: the two sides are the same set of
                         * books quoting one market, so adding them
                         * would report double.
                         */
                        $bookCount = (int) $h2h->max('books');
                    @endphp

                    <li data-board-game data-league="{{ $game['sport'] }}"
                        class="fx-panel fx-panel-lift fx-brushed flex flex-col p-5">
                        <div class="flex items-center justify-between gap-3">
                            <span class="rounded border border-[var(--vg-line)] px-1.5 py-0.5 font-[family-name:var(--font-mono)] text-[10px] font-bold uppercase tracking-wider text-[var(--vg-muted)]">
                                {{ strtoupper($game['sport']) }}
                            </span>
                            <span class="font-[family-name:var(--font-mono)] text-[11px] text-[var(--vg-muted)]">
                                {{ Clock::format($game['starts_at'], 'D g:i A') }}
                            </span>
                        </div>

                        <p class="mt-3.5 font-[family-name:var(--font-display)] text-base font-semibold leading-snug">
                            {{ $game['away'] }}
                            <span class="font-normal text-[var(--vg-muted)]">at</span>
                            {{ $game['home'] }}
                        </p>

                        {{-- Moneyline, both sides, which is the number everybody reads first. --}}
                        <dl class="mt-4 grid grid-cols-2 gap-2">
                            @foreach (['away', 'home'] as $side)
                                <div class="rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                                    <dt class="truncate font-[family-name:var(--font-mono)] text-[10px] uppercase tracking-wide text-[var(--vg-muted)]">
                                        {{ $game[$side.'_short'] }}
                                    </dt>
                                    <dd class="mt-0.5 font-[family-name:var(--font-mono)] text-sm font-bold tabular-nums" data-numeric>
                                        {{ ($price = $h2h->get($side)) ? $american($price['price_american']) : '—' }}
                                    </dd>
                                </div>
                            @endforeach
                        </dl>

                        {{-- Spread and total, when the books are posting them. --}}
                        @if ($spread->isNotEmpty() || $total->isNotEmpty())
                            <ul role="list" class="mt-3 space-y-1.5 font-[family-name:var(--font-mono)] text-[11px] text-[var(--vg-slate)]">
                                @if ($line = $spread->get('home') ?? $spread->get('away'))
                                    <li class="flex items-center justify-between gap-2">
                                        <span class="text-[var(--vg-muted)]">Spread</span>
                                        <span class="tabular-nums" data-numeric>
                                            {{ $game[($spread->has('home') ? 'home' : 'away').'_short'] }}
                                            {{ $line['line'] !== null ? ($line['line'] > 0 ? '+' : '').$line['line'] : '' }}
                                            ({{ $american($line['price_american']) }})
                                        </span>
                                    </li>
                                @endif

                                @if ($over = $total->get('over'))
                                    <li class="flex items-center justify-between gap-2">
                                        <span class="text-[var(--vg-muted)]">Total</span>
                                        <span class="tabular-nums" data-numeric>
                                            O {{ $over['line'] }} ({{ $american($over['price_american']) }})
                                        </span>
                                    </li>
                                @endif
                            </ul>
                        @endif

                        {{--
                            The engine's status on this game. Three
                            states and no fourth: it published a call, or
                            it did not. "No call" is not an absence of
                            data - it is the answer, and on most games it
                            is the right one.
                        --}}
                        <div class="mt-auto flex items-center justify-between gap-3 border-t border-[var(--vg-line)] pt-3.5">
                            @if ($opinion !== null)
                                <a href="{{ route('opinions') }}#{{ $opinion->public_id }}"
                                   class="group inline-flex min-w-0 items-center gap-1.5 text-xs font-semibold text-[var(--vg-green)]">
                                    <span class="truncate">{{ config('brand.name') }} called {{ $opinion->selection }}</span>
                                    <span aria-hidden="true" class="shrink-0 transition group-hover:translate-x-0.5">&rarr;</span>
                                </a>
                            @else
                                <span class="text-xs text-[var(--vg-muted)]">No call on this game</span>
                            @endif

                            <span class="shrink-0 font-[family-name:var(--font-mono)] text-[10px] text-[var(--vg-muted)]">
                                {{ $bookCount }} {{ Str::plural('book', $bookCount) }}
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="mt-8 flex flex-wrap items-center justify-between gap-4">
                <p class="font-[family-name:var(--font-mono)] text-[11px] text-[var(--vg-muted)]">
                    Best available price across tracked books. Not a pick.
                </p>

                <a href="{{ route('opinions') }}"
                   class="group inline-flex items-center gap-1.5 text-sm font-semibold text-[var(--vg-link)] underline decoration-[color-mix(in_srgb,var(--vg-link)_40%,transparent)] underline-offset-4 transition hover:decoration-[var(--vg-link)]">
                    Every call we have published, graded
                    <span aria-hidden="true" class="transition group-hover:translate-x-0.5">&rarr;</span>
                </a>
            </div>
        @endif
    </div>
</section>
