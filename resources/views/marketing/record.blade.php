<x-layouts.marketing title="Track record">
    <section class="relative isolate overflow-hidden border-b border-[var(--vg-line)]">
        <span aria-hidden="true" class="fx-grid"></span>
        <x-fx.section-glow class="left-1/2 top-0 h-72 w-[40rem] -translate-x-1/2 -translate-y-1/2" />

        <div class="relative mx-auto max-w-3xl px-6 py-20">
            <h1 class="font-[family-name:var(--font-display)] text-5xl font-bold leading-tight">
                The <span class="fx-gradient-text">record</span>, kept honestly.
            </h1>
            <p class="mt-5 max-w-xl text-[var(--vg-slate)]">
                Every published pick is written down before kickoff with its timestamp, its price,
                its book and the model probability behind it. The table rejects a row dated after
                kickoff, and rejects any edit to one already written.
            </p>
        </div>
    </section>

    @if ($historical)
        {{--
            Before any figure, not after.

            This page used to 404 outright whenever no market cleared
            the gate, and the reasoning was right: a win rate shown
            while nothing has been proved reads as "we beat the book"
            however carefully the surrounding words are chosen. But the
            gate tightened after these picks were published, and a 404
            meant the only honest thing the site said about eight real,
            graded games was nothing at all.

            So the page opens and leads with this. A caveat printed
            below a headline number is a caveat most readers never
            reach.
        --}}
        <section class="border-b border-[var(--vg-line)] bg-[var(--vg-navy)]">
            <div class="mx-auto max-w-3xl px-6 py-8">
                <h2 class="font-[family-name:var(--font-display)] text-lg font-bold text-[var(--vg-paper)]">
                    This is an archive. Nothing here is being published today.
                </h2>
                <div class="mt-3 space-y-3 text-sm text-[var(--vg-slate)]">
                    <p>
                        These picks were published before kickoff and graded afterwards, and the
                        results below are exactly what happened. What has changed is the bar: no
                        market currently meets the standard required to publish a pick, so the
                        engine is publishing none.
                    </p>
                    <p>
                        <strong class="text-[var(--vg-paper)]">Read these figures as history, not as a claim.</strong>
                        They come from markets that cleared an earlier, lower bar. They are not
                        evidence that {{ config('brand.name') }} beats the closing line today,
                        because no market has yet shown that.
                    </p>
                    <p>
                        What the engine is publishing instead is opinions, which are governed by
                        calibration rather than by that gate, and every one of them is graded in
                        public &mdash;
                        <a href="{{ route('opinions') }}" class="underline hover:text-[var(--vg-paper)]">see the opinion record</a>.
                    </p>
                </div>
            </div>
        </section>
    @endif

    {{-- The headline figures, or the honest absence of them. --}}
    <section class="border-b border-[var(--vg-line)]">
        <div class="mx-auto grid max-w-5xl gap-4 px-6 py-10 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-tile label="Published picks"
                         :value="$summary['published'] ?: null"
                         :sample="$summary['pending'] ? $summary['pending'].' still running' : 'none running'" />

            <x-stat-tile label="Record"
                         :value="$summary['settled'] === 0
                            ? null
                            : $summary['won'].'-'.$summary['lost'].($summary['pushed'] ? '-'.$summary['pushed'] : '')"
                         :sample="'n = '.$summary['settled']" />

            <x-stat-tile label="Units"
                         :value="$summary['units'] === null ? null : sprintf('%+.2f', $summary['units'])"
                         :sample="$summary['roi'] === null
                            ? 'nothing settled yet'
                            : sprintf('%+.1f%% per pick', $summary['roi'] * 100)"
                         :tone="($summary['units'] ?? 0) > 0 ? 'edge' : (($summary['units'] ?? 0) < 0 ? 'loss' : 'ink')" />

            <x-stat-tile label="Closing line value"
                         :value="$summary['clv'] === null ? null : sprintf('%+.2f%%', $summary['clv'] * 100)"
                         :sample="'n = '.$summary['clv_sample']"
                         :tone="($summary['clv'] ?? 0) > 0 ? 'edge' : 'ink'" />
        </div>
    </section>

    {{--
        The published picks, which is what a visitor clicking "Track
        record" came to see.

        This section exists because the page used to say "nothing
        published yet, and there will be nothing until a market clears
        the gate" - true of the proven tier below, and badly misleading
        on a site that publishes picks every day and grades all of them.
        A record that reports none of what was published is not being
        careful, it is being wrong.

        Kept in its own section, with its own totals, above a proven tier
        that has its own. The two are never added together: a pick from a
        proven market is a stronger claim than an opinion, and summing
        them destroys the stronger one.
    --}}
    <section class="mx-auto max-w-5xl px-6 pt-12">
        <h2 class="font-[family-name:var(--font-display)] text-2xl font-bold">
            {{ config('brand.name') }}'s published picks
        </h2>

        <p class="mt-3 max-w-2xl text-sm leading-relaxed text-[var(--vg-slate)]">
            Every pick published before kickoff, with the confidence stated at the time, graded
            against the final score. These are the engine's own opinions from markets whose
            probabilities have been measured against real results &mdash; not proven edges, and
            the losers are in the table with the winners.
        </p>

        <dl class="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-[var(--vg-line)] bg-[var(--vg-line)] sm:grid-cols-4">
            @foreach ([
                ['Published', number_format($opinionRecord['published']), 'before kickoff'],
                ['Graded', number_format($opinionRecord['graded']), 'games finished'],
                [
                    'Win rate',
                    $opinionRecord['win_rate'] === null ? '—' : number_format($opinionRecord['win_rate'] * 100, 1).'%',
                    $opinionRecord['graded'] > 0 ? 'n = '.number_format($opinionRecord['graded']) : 'nothing graded yet',
                ],
                [
                    'Units',
                    $opinionRecord['units'] === null ? '—' : ($opinionRecord['units'] > 0 ? '+' : '').number_format($opinionRecord['units'], 2),
                    $opinionRecord['graded'] > 0 ? 'at one unit a pick' : 'nothing graded yet',
                ],
            ] as [$label, $value, $sample])
                <div class="bg-[var(--vg-navy)] px-5 py-4">
                    <dt class="text-[11px] uppercase tracking-wide text-[var(--vg-slate)]">{{ $label }}</dt>
                    <dd class="mt-1 font-[family-name:var(--font-mono)] text-xl font-bold" data-numeric>{{ $value }}</dd>
                    <dd class="mt-0.5 text-[11px] text-[var(--vg-muted)]">{{ $sample }}</dd>
                </div>
            @endforeach
        </dl>

        @if ($opinions->isEmpty())
            <p class="panel mt-5 p-6 text-center text-sm text-[var(--vg-slate)]">
                Nothing published yet. Picks appear here as the engine publishes them.
            </p>
        @else
            <div class="panel mt-5 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Published picks and how they turned out.</caption>
                    <thead class="border-b border-[var(--vg-line)] text-[11px] uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr>
                            <th scope="col" class="px-4 py-2">Kickoff</th>
                            <th scope="col" class="px-4 py-2">Pick</th>
                            <th scope="col" class="px-4 py-2 text-right">We said</th>
                            <th scope="col" class="px-4 py-2 text-right">Price</th>
                            <th scope="col" class="px-4 py-2">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($opinions as $opinion)
                            <tr class="border-b border-[var(--vg-line)] last:border-0">
                                <td class="px-4 py-3 text-[var(--vg-slate)]" data-numeric>
                                    <x-local-time :at="$opinion->kickoff_at" format="d M H:i" />
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-semibold">{{ $opinion->selection }}</span>
                                    <span class="block text-xs text-[var(--vg-slate)]">
                                        {{ strtoupper((string) $opinion->sport) }} · {{ $opinion->matchup }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right" data-numeric>{{ $opinion->confidencePercent() }}%</td>
                                <td class="px-4 py-3 text-right" data-numeric>
                                    {{ $opinion->price_american > 0 ? '+' : '' }}{{ $opinion->price_american }}
                                </td>
                                <td class="px-4 py-3">
                                    @if ($opinion->result === null)
                                        <span class="text-xs text-[var(--vg-slate)]">pending</span>
                                    @else
                                        <span @class([
                                            'badge',
                                            'badge-edge' => $opinion->result->won(),
                                            'badge-pass' => ! $opinion->result->won(),
                                        ])>{{ $opinion->result->status }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-4 text-sm">
                <a class="text-[var(--vg-link)] underline" href="{{ route('opinions') }}">
                    Every published pick, and whether the confidence was true &rarr;
                </a>
            </p>
        @endif
    </section>

    <section class="mx-auto max-w-5xl px-6 py-12">
        <h2 class="font-[family-name:var(--font-display)] text-2xl font-bold">
            Picks from a proven market
        </h2>

        <p class="mt-3 max-w-2xl text-sm leading-relaxed text-[var(--vg-slate)]">
            The stronger claim, and a separate table. A market reaches this tier only once its model
            has beaten the closing line over a measured sample. It is counted on its own and never
            added to the figures above.
        </p>

        @if ($summary['published'] === 0)
            {{--
                Nothing published. Said as a fact about the gate rather
                than dressed up: the product does not sell from a market
                until it has beaten the closing line, and no market has.
            --}}
            <div class="panel mt-5 p-8 text-center">
                <h3 class="font-[family-name:var(--font-display)] text-lg font-bold">
                    No market has cleared the gate
                </h3>
                <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-[var(--vg-slate)]">
                    {{ config('brand.name') }} sells a pick only from a market that has already beaten
                    the closing line over a measured sample. None has, so this table is empty &mdash;
                    and it stays empty until one does.
                </p>
                {{--
                    This used to end "...would mean we were willing to
                    make numbers up". It was arguing for the product and
                    read as an admission against it, which is a failure
                    whatever it meant. The figures above come from odds,
                    box scores, player availability and the engine's own
                    measured history; what is missing here is not data,
                    it is a market that has cleared a harder bar.
                --}}
                <p class="mx-auto mt-4 max-w-xl text-sm leading-relaxed text-[var(--vg-slate)]">
                    Every figure {{ config('brand.name') }} publishes comes from real data &mdash;
                    live odds, box scores, player availability and the engine's own measured history.
                    That is what the picks above are built from. This tier is a harder bar on top of
                    it, and nothing has met that bar yet.
                </p>
                <p class="mt-6">
                    <a class="text-[var(--vg-link)] underline" href="{{ route('how-it-works') }}">
                        How the gate works
                    </a>
                </p>
            </div>
        @else
            {{-- Filters: §7 asks for sport, market and month. --}}
            <form method="GET" action="{{ route('record') }}" class="flex flex-wrap items-end gap-3">
                <label class="block text-sm">
                    <span class="text-[var(--vg-slate)]">Sport</span>
                    <select name="sport" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                        <option value="">All</option>
                        @foreach ($options['sports'] as $sport)
                            <option value="{{ $sport }}" @selected($filters['sport'] === $sport)>
                                {{ strtoupper($sport) }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block text-sm">
                    <span class="text-[var(--vg-slate)]">Market</span>
                    <select name="market" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                        <option value="">All</option>
                        @foreach ($options['markets'] as $market)
                            <option value="{{ $market }}" @selected($filters['market'] === $market)>
                                {{ $market }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block text-sm">
                    <span class="text-[var(--vg-slate)]">Month</span>
                    <select name="month" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                        <option value="">All</option>
                        @foreach ($options['months'] as $month)
                            <option value="{{ $month }}" @selected($filters['month'] === $month)>
                                {{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month.'-01')->format('F Y') }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <button type="submit" class="rounded-lg border border-[var(--vg-line)] px-4 py-2 text-sm font-semibold">
                    Filter
                </button>

                @if (array_filter($filters))
                    <a href="{{ route('record') }}" class="text-sm text-[var(--vg-slate)] underline">Clear</a>
                @endif
            </form>

            @if ($curve['points'] !== [])
                <div class="panel mt-6 p-5">
                    <h2 class="font-[family-name:var(--font-display)] font-semibold">Cumulative units</h2>
                    <p class="mt-1 text-xs text-[var(--vg-slate)]">
                        One unit a pick, settled in order. Flat staking, because it is the only plan
                        this record can honestly assume a reader followed.
                    </p>
                    <x-chart.area :labels="$curve['labels']" :points="$curve['points']"
                                  label="Cumulative units" class="mt-4" />
                </div>
            @endif

            {{-- Every pick. Including the losers; that is the point. --}}
            <div class="panel mt-6 overflow-hidden">
                <h2 class="border-b border-[var(--vg-line)] px-5 py-3.5 font-[family-name:var(--font-display)] font-semibold">
                    Every published pick
                </h2>

                <div class="w-full overflow-x-auto">
                    <table class="w-full min-w-[820px] text-left text-sm">
                        <caption class="sr-only">Published picks and their results</caption>
                        <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                            <tr class="border-b border-[var(--vg-line)]">
                                <th scope="col" class="px-5 py-2.5 font-medium">Published</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">Pick</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">Price</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">Model</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">Result</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">Units</th>
                                <th scope="col" class="px-5 py-2.5 text-right font-medium">CLV</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($picks as $pick)
                                <tr class="border-b border-[var(--vg-line)] last:border-0">
                                    <td class="px-5 py-3 text-xs text-[var(--vg-slate)]">
                                        <a class="underline hover:text-[var(--vg-paper)]"
                                           href="{{ route('record.pick', $pick) }}">
                                            <x-local-time :at="$pick->published_at" format="j M, H:i" />
                                        </a>
                                    </td>
                                    <td class="px-3 py-3">
                                        <span class="font-medium">{{ $pick->selection }}</span>
                                        <span class="block text-xs uppercase text-[var(--vg-slate)]">
                                            {{ $pick->sport }} &middot; {{ $pick->market }}
                                            @if ($pick->book_title) &middot; {{ $pick->book_title }} @endif
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                        {{ $pick->priceWritten() }}
                                    </td>
                                    <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                        {{ $pick->model_prob === null ? '—' : round($pick->model_prob * 100).'%' }}
                                    </td>
                                    <td class="px-3 py-3">
                                        @if ($pick->result === null)
                                            <span class="badge">Running</span>
                                        @else
                                            <span @class([
                                                'badge',
                                                'badge-edge' => $pick->result->status === 'won',
                                                'badge-pass' => in_array($pick->result->status, ['push', 'void'], true),
                                            ])>{{ ucfirst($pick->result->status) }}</span>
                                        @endif
                                    </td>
                                    <td @class([
                                        'px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums',
                                        'text-[var(--vg-edge)]' => ($pick->result->units ?? 0) > 0,
                                        'text-[var(--vg-loss)]' => ($pick->result->units ?? 0) < 0,
                                    ]) data-numeric>
                                        {{ $pick->result === null ? '—' : sprintf('%+.2f', $pick->result->units) }}
                                    </td>
                                    <td class="px-5 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                        {{ $pick->result?->clv === null ? '—' : sprintf('%+.2f%%', $pick->result->clv * 100) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-4">{{ $picks->links() }}</div>
            </div>
        @endif
    </section>

    <section class="mx-auto max-w-3xl px-6 pb-16">
        <h2 class="font-[family-name:var(--font-display)] text-xl font-bold">What is and is not on this page</h2>

        <ul class="mt-5 space-y-3 text-sm text-[var(--vg-slate)]">
            <li class="flex gap-3">
                <span aria-hidden="true" class="mt-1 shrink-0 text-[var(--vg-edge)]">&check;</span>
                <span>
                    Every pick published, before kickoff, at the price and book that were available.
                    The database rejects a row dated after kickoff, and rejects any edit to one
                    already written.
                </span>
            </li>
            <li class="flex gap-3">
                <span aria-hidden="true" class="mt-1 shrink-0 text-[var(--vg-edge)]">&check;</span>
                <span>Losers and pushes, in the same table and the same totals as the winners.</span>
            </li>
            <li class="flex gap-3">
                <span aria-hidden="true" class="mt-1 shrink-0 text-[var(--vg-edge)]">&check;</span>
                <span>
                    Every rate with its sample size — and no rate at all where there is no sample,
                    because an unmeasured figure and a zero look identical and are not.
                </span>
            </li>
            <li class="flex gap-3">
                <span aria-hidden="true" class="mt-1 shrink-0 text-[var(--vg-loss)]">&times;</span>
                <span>
                    No backtests, no paper trading, no simulated results. They are not mixed into
                    these numbers and they are not shown beside them — a record is only evidence if
                    it is one kind of thing.
                </span>
            </li>
        </ul>
    </section>
</x-layouts.marketing>
