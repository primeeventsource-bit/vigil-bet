<x-layouts.app title="My parlays">
    <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">My parlays</h1>
    <p class="mt-1 max-w-2xl text-sm text-[var(--vg-slate)]">
        Your own selections, graded against the final scores. These are your decisions and
        your record — kept apart from VIGIL's picks, so neither can be mistaken for the other.
    </p>

    {{-- The layout already flashes session('status'); a second copy here
         printed the same sentence twice, which reads as a bug. --}}

    {{--
        Every rate carries its sample or is not shown at all, which is
        what x-stat-tile exists to enforce: a win rate over three parlays
        is a number about three parlays, and printed bare it invites being
        read as a number about the person.
    --}}
    <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <x-stat-tile label="Drafts"
                     :value="$summary['drafts'] === 0 ? null : (string) $summary['drafts']"
                     sample="not wagers" />

        <x-stat-tile label="Running"
                     :value="$summary['pending'] === 0 ? null : (string) $summary['pending']"
                     sample="waiting on games" />

        <x-stat-tile label="Win rate"
                     :value="$summary['win_rate'] === null ? null : round($summary['win_rate'] * 100, 1).'%'"
                     :sample="$summary['decided'] === 0 ? 'nothing decided yet' : 'n = '.$summary['decided']" />

        <x-stat-tile label="Profit"
                     :value="$summary['profit_cents'] === null
                        ? null
                        : sprintf('%s$%s', $summary['profit_cents'] < 0 ? '-' : '', number_format(abs($summary['profit_cents']) / 100, 2))"
                     :tone="$summary['profit_cents'] === null ? 'ink' : ($summary['profit_cents'] < 0 ? 'loss' : 'edge')"
                     :sample="$summary['settled'] === 0
                        ? 'nothing settled yet'
                        : 'real money, '.$summary['settled'].' settled'" />
    </div>

    @if ($parlays->isEmpty())
        <div class="panel mt-6 px-5 py-12 text-center">
            <p class="text-sm text-[var(--vg-slate)]">
                Nothing saved yet. Build a slip on the
                <a class="text-[var(--vg-link)] underline" href="{{ route('parlays') }}">parlay builder</a>
                and save it to start a record.
            </p>
        </div>
    @else
        <div class="mt-6 space-y-4">
            @foreach ($parlays as $parlay)
                @php
                    /*
                     * Void is excluded from "settled" for the reason the
                     * straight-bet record excludes it: a parlay that never
                     * ran is not a result.
                     */
                    $isSettled = in_array($parlay->status, ['won', 'lost', 'push'], true);
                    $profit = $parlay->profitCents();
                    $price = $parlay->payoutPriceAmerican();
                @endphp

                <section class="panel overflow-hidden">
                    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--vg-line)] px-5 py-3.5">
                        <div class="min-w-0">
                            <p class="font-[family-name:var(--font-display)] font-semibold">
                                {{ $parlay->legs->count() }}-leg parlay
                                @if ($price !== null)
                                    <span class="ml-1 font-[family-name:var(--font-mono)] text-[var(--vg-slate)] tabular-nums">
                                        {{ $price > 0 ? '+' : '' }}{{ $price }}
                                    </span>
                                @endif
                            </p>
                            <p class="mt-0.5 text-xs text-[var(--vg-slate)]">
                                <span class="tabular-nums">${{ number_format($parlay->stake_cents / 100, 2) }}</span>
                                @if ($parlay->book)
                                    &middot; {{ $parlay->book_title ?? $parlay->book }}
                                @endif
                                @if ($parlay->placed_at)
                                    &middot; <x-local-time :at="$parlay->placed_at" format="j M Y, H:i" />
                                @endif
                                @if ($parlay->ticket_reference)
                                    &middot; ticket {{ $parlay->ticket_reference }}
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            @if ($parlay->is_paper)
                                {{-- Said plainly, so a paper record is never read as money. --}}
                                <span class="rounded-full border border-[var(--vg-line)] px-2.5 py-1 text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                                    Paper
                                </span>
                            @endif

                            <span @class([
                                'rounded-full px-2.5 py-1 text-xs font-semibold uppercase tracking-wide',
                                'bg-[var(--vg-edge)] text-[var(--vg-night)]' => $parlay->status === 'won',
                                'bg-[var(--vg-loss)] text-[var(--vg-paper)]' => $parlay->status === 'lost',
                                'border border-[var(--vg-line)] text-[var(--vg-slate)]' => ! in_array($parlay->status, ['won', 'lost'], true),
                            ])>
                                {{ $parlay->status }}
                            </span>
                        </div>
                    </header>

                    <ul class="divide-y divide-[var(--vg-line)]">
                        @foreach ($parlay->legs as $leg)
                            <li class="flex flex-wrap items-start justify-between gap-3 px-5 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium">{{ $leg->selection }}</p>
                                    <p class="mt-0.5 text-xs uppercase text-[var(--vg-slate)]">
                                        {{ $leg->sport }} &middot; {{ $leg->market }}
                                        &middot;
                                        <span class="font-[family-name:var(--font-mono)] tabular-nums">
                                            {{ $leg->price_american > 0 ? '+' : '' }}{{ $leg->price_american }}
                                        </span>
                                    </p>

                                    {{--
                                        What the engine said when this leg was
                                        saved, frozen then. "Passed" is not
                                        "disagreed": a probability the engine
                                        held while declining the price is a
                                        different statement from it backing
                                        the other side, and flattening the two
                                        would misreport what it said.
                                    --}}
                                    <p class="mt-1 text-xs text-[var(--vg-slate)]">
                                        @switch($leg->model_agreement)
                                            @case('agreed')
                                                VIGIL backed this
                                                @break
                                            @case('passed')
                                                VIGIL had a number and passed on the price
                                                @break
                                            @default
                                                VIGIL had no qualifying prediction
                                        @endswitch

                                        @if ($leg->model_probability !== null)
                                            &middot;
                                            <span class="tabular-nums">{{ round($leg->model_probability * 100, 1) }}%</span>
                                            @if ($leg->model_version)
                                                ({{ $leg->model_version }})
                                            @endif
                                        @endif
                                    </p>
                                </div>

                                <span @class([
                                    'shrink-0 text-xs uppercase tracking-wide',
                                    'text-[var(--vg-edge)]' => $leg->status === 'won',
                                    'text-[var(--vg-loss)]' => $leg->status === 'lost',
                                    'text-[var(--vg-slate)]' => ! in_array($leg->status, ['won', 'lost'], true),
                                ])>
                                    {{ $leg->status }}
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($isSettled)
                        <footer class="flex flex-wrap items-center gap-x-6 gap-y-1 border-t border-[var(--vg-line)] px-5 py-3 text-xs">
                            <span class="text-[var(--vg-slate)]">
                                Returned
                                <span class="tabular-nums text-[var(--vg-paper)]">
                                    ${{ number_format(($parlay->return_cents ?? 0) / 100, 2) }}
                                </span>
                            </span>

                            @if ($profit !== null)
                                <span class="text-[var(--vg-slate)]">
                                    {{ $profit < 0 ? 'Lost' : 'Made' }}
                                    <span class="tabular-nums text-[var(--vg-paper)]">
                                        ${{ number_format(abs($profit) / 100, 2) }}
                                    </span>
                                </span>
                            @endif

                            @if ($parlay->book_return_cents !== null && $parlay->book_return_cents !== $parlay->return_cents)
                                {{--
                                    Shown rather than reconciled away. A
                                    disagreement between our figure and the
                                    book's is the thing worth seeing, and
                                    overwriting ours would destroy the
                                    evidence of it.
                                --}}
                                <span class="text-[var(--vg-loss)]">
                                    Book recorded
                                    <span class="tabular-nums">${{ number_format($parlay->book_return_cents / 100, 2) }}</span>
                                </span>
                            @endif
                        </footer>
                    @elseif ($parlay->status === 'draft')
                        <footer class="border-t border-[var(--vg-line)] px-5 py-3 text-xs text-[var(--vg-slate)]">
                            A draft. Not counted as a wager and not graded.
                        </footer>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</x-layouts.app>
