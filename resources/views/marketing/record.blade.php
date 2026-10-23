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

    <section class="mx-auto max-w-5xl px-6 py-12">
        @if ($summary['published'] === 0)
            {{--
                Nothing published. Said as a fact about the gate rather
                than dressed up: the product does not sell from a market
                until it has beaten the closing line, and no market has.
                A page that implied otherwise would undo the one thing
                this page exists for.
            --}}
            <div class="panel p-8 text-center">
                <h2 class="font-[family-name:var(--font-display)] text-xl font-bold">
                    Nothing published yet
                </h2>
                <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-[var(--vg-slate)]">
                    {{ config('brand.name') }} publishes a pick only from a market that has already
                    beaten the closing line over a measured sample. No market has yet cleared that
                    gate, so there is nothing on this page — and there will be nothing until one
                    does.
                </p>
                <p class="mx-auto mt-4 max-w-xl text-sm leading-relaxed text-[var(--vg-slate)]">
                    This is the state the product is designed to be honest about. A record page with
                    numbers on it before any pick had been published would tell you nothing except
                    that we were willing to make numbers up.
                </p>
                <p class="mt-6">
                    <a class="text-[var(--vg-blue)] underline" href="{{ route('how-it-works') }}">
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
                                            {{ $pick->published_at->format('j M, H:i') }}
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
