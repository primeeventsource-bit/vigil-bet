<x-layouts.app title="Dashboard">
    <x-data-delayed :notice="$delayed" />

    {{-- Hero and the four headline tiles. --}}
    <section class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-[minmax(0,1fr)_auto]">
        <div>
            <h1 class="font-[family-name:var(--font-display)] text-3xl font-bold leading-tight">
                Smarter Bets.<br>
                <span class="text-[var(--vg-edge)]">Sharper Edges.</span>
            </h1>
            <p class="mt-3 max-w-md text-sm text-[var(--vg-slate)]">
                AI-driven insights. Real-time odds.<br>
                A more disciplined you.
            </p>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <x-stat-tile label="Active Picks" :value="$activePicks" />

            <x-stat-tile
                label="Avg Confidence"
                :value="$avgConfidence === null ? '—' : round($avgConfidence * 100).'%'"
                :sample="$activePicks ? 'n = '.$activePicks : 'no picks'" />

            {{--
                Units are the track record. The spec is explicit: with no
                settled picks, show no rate at all - a number here before
                there is evidence for it is the one thing the product
                cannot do and still be worth buying.
            --}}
            <x-stat-tile
                label="This Week"
                :value="$bankroll['units'] === null ? '—' : $bankroll['units']"
                :sample="'n = '.$bankroll['settled']"
                :tone="$bankroll['units'] === null ? 'ink' : 'edge'" />

            <x-stat-tile
                label="All Time"
                :value="$bankroll['units'] === null ? '—' : $bankroll['units']"
                :sample="'n = '.$bankroll['settled']"
                :tone="$bankroll['units'] === null ? 'ink' : 'edge'" />
        </div>
    </section>

    <section class="mt-5 grid min-w-0 grid-cols-[minmax(0,1fr)] gap-5 xl:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)]">
        {{-- Top picks --}}
        <div class="panel min-w-0 overflow-hidden">
            <div class="flex items-center justify-between border-b border-[var(--vg-line)] px-5 py-3.5">
                <h2 class="font-[family-name:var(--font-display)] font-semibold">Top AI Picks</h2>
                <a href="{{ route('picks') }}" class="text-xs text-[var(--vg-slate)] hover:text-[var(--vg-paper)]">
                    View all &rarr;
                </a>
            </div>

            {{--
                Sport filters. Links rather than script, so they work with
                JavaScript off and each filtered board has its own URL.
                Only sports the engine has actually judged appear - a tab
                for a sport with nothing behind it is a dead end.
            --}}
            <div class="flex flex-wrap gap-1.5 border-b border-[var(--vg-line)] px-5 py-2.5">
                @php($active = request()->query('sport'))

                <a href="{{ route('today') }}" @class([
                    'rounded-md px-2.5 py-1 text-xs font-semibold transition',
                    'bg-[color-mix(in_srgb,var(--vg-edge)_18%,transparent)] text-[var(--vg-edge)]' => ! $active,
                    'text-[var(--vg-slate)] hover:text-[var(--vg-paper)]' => (bool) $active,
                ])>All</a>

                @foreach ($availableSports as $code)
                    <a href="{{ route('today', ['sport' => $code]) }}" @class([
                        'rounded-md px-2.5 py-1 text-xs font-semibold uppercase transition',
                        'bg-[color-mix(in_srgb,var(--vg-edge)_18%,transparent)] text-[var(--vg-edge)]' => $active === $code,
                        'text-[var(--vg-slate)] hover:text-[var(--vg-paper)]' => $active !== $code,
                    ])>{{ $code }}</a>
                @endforeach
            </div>

            @if ($picks->isNotEmpty())
                {{-- Cards on a phone, the table from md up. See PickCard. --}}
                <ul class="space-y-3 p-4 md:hidden">
                    @foreach ($picks as $pick)
                        <x-app.pick-card :pick="$pick" />
                    @endforeach
                </ul>

                <div class="hidden w-full max-w-full overflow-x-auto md:block">
                    <table class="w-full min-w-[620px] text-left text-sm">
                        <caption class="sr-only">Picks from proven markets</caption>
                        <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                            <tr class="border-b border-[var(--vg-line)]">
                                <th scope="col" class="px-5 py-2.5 font-medium">Game</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">Pick</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">Confidence</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">Odds</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">Edge</th>
                                <th scope="col" class="px-5 py-2.5"><span class="sr-only">Log</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($picks as $pick)
                                <x-app.pick-row :pick="$pick" />
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <x-app.no-proven-picks :near-misses="$nearMisses" />
            @endif
        </div>

        {{-- Bankroll --}}
        <div class="panel min-w-0 p-5">
            <div class="flex items-center justify-between">
                <h2 class="font-[family-name:var(--font-display)] font-semibold">Bankroll</h2>
                <span class="text-xs text-[var(--vg-slate)]">This month</span>
            </div>

            @if ($bankroll['balance_cents'] !== null)
                <p class="mt-3 font-[family-name:var(--font-mono)] text-3xl font-bold tabular-nums" data-numeric>
                    ${{ number_format($bankroll['balance_cents'] / 100, 2) }}
                </p>

                <x-chart.area :points="$bankroll['series']" :labels="$bankroll['labels']" label="Bankroll" />
            @else
                <p class="mt-3 font-[family-name:var(--font-mono)] text-3xl font-bold tabular-nums text-[var(--vg-slate)]" data-numeric>
                    —
                </p>
                <p class="mt-2 text-sm text-[var(--vg-slate)]">
                    Bankroll tracking is not set up yet. Nothing here is a projection.
                </p>
                <div class="mt-4 rounded-lg border border-dashed border-[var(--vg-line)] px-4 py-8 text-center text-xs text-[var(--vg-slate)]">
                    No settled bets to chart
                </div>
            @endif

            <dl class="mt-5 grid grid-cols-3 gap-3 border-t border-[var(--vg-line)] pt-4 text-sm">
                @foreach ([
                    ['Win Rate', $bankroll['win_rate'] === null ? '—' : round($bankroll['win_rate'] * 100).'%'],
                    ['ROI', $bankroll['roi'] === null ? '—' : sprintf('%+.1f%%', $bankroll['roi'] * 100)],
                    ['Units', $bankroll['units'] ?? '—'],
                ] as [$term, $value])
                    <div>
                        <dt class="text-xs text-[var(--vg-slate)]">{{ $term }}</dt>
                        <dd class="mt-0.5 font-[family-name:var(--font-mono)] font-semibold tabular-nums" data-numeric>
                            {{ $value }}
                        </dd>
                    </div>
                @endforeach
            </dl>

            {{-- Sample size, always, beside every rate above it. --}}
            <p class="mt-2 text-[11px] text-[var(--vg-slate)]" data-numeric>
                From {{ $bankroll['settled'] }} settled pick(s).
            </p>
        </div>
    </section>

    <section class="mt-5 grid min-w-0 grid-cols-[minmax(0,1fr)] gap-5 xl:grid-cols-[repeat(3,minmax(0,1fr))]">
        <x-app.movement-panel :movement="$movement" />
        <x-app.confidence-panel :sports="$sports" :average="$avgConfidence" />
        <x-app.parlay-panel />
    </section>
</x-layouts.app>
