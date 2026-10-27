@props(['games', 'pricedAt' => null])

@php
    use App\Support\Clock;
@endphp

{{--
    The live odds band from the reference layout.

    Everything in it is real. The reference draws Bills vs Chiefs at
    -3.5 (-110) and dates the strip "Updated 12s ago", all of it typed
    into the markup; these are upcoming fixtures at the best consensus
    moneyline across the books actually tracked, and the timestamp is
    when the newest of those prices was pulled.

    Nothing renders when there is nothing to show. An empty ticker reads
    as a broken feed, and the page has one honest absence to explain
    already without acquiring a second.
--}}
@if ($games->isNotEmpty())
    <section aria-labelledby="odds-ticker-heading"
             class="border-b border-[var(--vg-line)] bg-[var(--vg-night)]">
        <div class="mx-auto max-w-7xl px-6 py-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="odds-ticker-heading"
                    class="flex items-center gap-2 font-[family-name:var(--font-mono)] text-[11px] font-bold uppercase tracking-[0.18em] text-[var(--vg-paper)]">
                    {{--
                        The pulse is decoration and is hidden from the
                        reduced-motion reader by the stylesheet, not by a
                        second copy of this markup.
                    --}}
                    <span aria-hidden="true" class="relative inline-flex h-1.5 w-1.5 text-[var(--vg-edge)]">
                        <span class="fx-pulse absolute inset-0 rounded-full"></span>
                        <span class="relative inline-block h-1.5 w-1.5 rounded-full bg-current"></span>
                    </span>
                    Live odds feed
                </h2>

                @if ($pricedAt !== null)
                    <p class="font-[family-name:var(--font-mono)] text-[11px] text-[var(--vg-muted)]">
                        Prices pulled {{ $pricedAt->diffForHumans() }}
                    </p>
                @endif
            </div>

            <ul role="list" class="mt-4 grid gap-px bg-[var(--vg-line)] sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($games as $game)
                    @php
                        $h2h = collect($game['markets']['h2h'] ?? [])->keyBy('side');
                    @endphp

                    <li class="flex items-center justify-between gap-4 bg-[var(--vg-navy)] px-4 py-3">
                        <div class="min-w-0">
                            <p class="font-[family-name:var(--font-mono)] text-[10px] uppercase tracking-wide text-[var(--vg-muted)]">
                                {{ strtoupper($game['sport']) }}
                                &middot;
                                {{ Clock::format($game['starts_at'], 'D g:i A') }}
                            </p>
                            <p class="truncate text-sm font-semibold text-[var(--vg-paper)]">
                                {{ $game['away'] }} at {{ $game['home'] }}
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-col items-end gap-0.5 font-[family-name:var(--font-mono)] text-[11px]">
                            @foreach (['away', 'home'] as $side)
                                @if ($price = $h2h->get($side))
                                    <span class="text-[var(--vg-slate)]">
                                        {{ $game[$side.'_short'] }}
                                        <span class="font-bold text-[var(--vg-paper)]">
                                            {{ $price['price_american'] > 0 ? '+' : '' }}{{ $price['price_american'] }}
                                        </span>
                                    </span>
                                @endif
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ul>

            {{--
                Said once, plainly. These are the books' numbers, not a
                recommendation, and a strip of prices on a gambling site
                with nothing qualifying it is the thing the rest of this
                page is arguing against.
            --}}
            <p class="mt-3 font-[family-name:var(--font-mono)] text-[11px] text-[var(--vg-muted)]">
                Best available price across tracked books. Not a pick.
            </p>
        </div>
    </section>
@endif
