<x-layouts.admin title="Shadow record">
    {{--
        Admin only. Every number on this page is an experiment, and the
        page says so more than once on purpose - the one way this becomes
        a problem is somebody screenshotting it as a track record.
    --}}
    <div class="panel border-[color-mix(in_srgb,var(--vg-warning)_45%,transparent)] p-5">
        <h1 class="font-[family-name:var(--font-display)] text-xl font-bold">
            Shadow record <span class="text-[var(--vg-warning)]">— not results</span>
        </h1>

        <p class="mt-3 max-w-3xl text-sm leading-relaxed text-[var(--vg-slate)]">
            These are selections the engine <strong class="text-[var(--vg-paper)]">would have bet</strong>
            if the verdict gate had let it. None of them was published, none was shown to a customer,
            and none could have been placed. They are recorded before kickoff at the price that was on
            the board, and settled against the real result, so that a market has something behind it
            if it ever clears the gate on its own.
        </p>

        <p class="mt-3 max-w-3xl text-sm leading-relaxed text-[var(--vg-slate)]">
            They are not a track record and must never be shown as one. The public record contains
            published picks and nothing else, which is the only reason it is worth anything.
        </p>
    </div>

    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-tile label="Recorded" :value="number_format($recorded)" sample="selections the gate declined" />
        <x-stat-tile label="Awaiting a result" :value="number_format($pending)" sample="game not finished or graded" />

        {{--
            No rate without its sample. An unmeasured figure and a zero
            look identical on a page and are not the same thing.
        --}}
        <x-stat-tile
            label="Win rate"
            :value="$winRate === null ? '—' : number_format($winRate * 100, 1).'%'"
            :sample="$gradedCount > 0 ? 'n = '.number_format($gradedCount) : 'nothing graded yet'" />

        <x-stat-tile
            label="Units"
            :value="$gradedCount > 0 ? number_format($units, 2) : '—'"
            sample="per unit staked, signed" />
    </div>

    <div class="panel mt-5 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Closing line value</h2>

        <p class="mt-1 max-w-3xl text-xs text-[var(--vg-slate)]">
            The figure that matters here. Whether these won is mostly noise at this sample size;
            whether they beat the number the market closed at is what would eventually justify a
            verdict. Positive means the price shortened after the selection was recorded.
        </p>

        <p class="mt-4 font-[family-name:var(--font-mono)] text-3xl font-bold" data-numeric>
            {{ $meanClv === null ? '—' : ($meanClv > 0 ? '+' : '').number_format($meanClv * 100, 2).'%' }}
        </p>

        <p class="mt-1 text-xs text-[var(--vg-slate)]">
            {{ $clvCount > 0 ? 'mean across n = '.number_format($clvCount).' with a stored closing line' : 'no closing lines stored yet' }}
        </p>
    </div>

    @if ($bySport->isNotEmpty())
        <h2 class="mt-8 font-[family-name:var(--font-display)] text-lg font-semibold">By sport</h2>

        <div class="panel mt-3 overflow-hidden">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Shadow results by sport. Not a track record.</caption>
                <thead class="border-b border-[var(--vg-line)] text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                    <tr>
                        <th scope="col" class="px-4 py-2">Sport</th>
                        <th scope="col" class="px-4 py-2 text-right">Settled</th>
                        <th scope="col" class="px-4 py-2 text-right">Units</th>
                        <th scope="col" class="px-4 py-2 text-right">Mean CLV</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bySport as $row)
                        <tr class="border-b border-[var(--vg-line)] last:border-0">
                            <td class="px-4 py-3 uppercase">{{ $row->sport }}</td>
                            <td class="px-4 py-3 text-right" data-numeric>{{ number_format($row->n) }}</td>
                            <td class="px-4 py-3 text-right" data-numeric>{{ number_format((float) $row->units, 2) }}</td>
                            <td class="px-4 py-3 text-right" data-numeric>
                                {{ $row->clv === null ? '—' : number_format((float) $row->clv * 100, 2).'%' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2 class="mt-8 font-[family-name:var(--font-display)] text-lg font-semibold">Most recent</h2>

    @if ($recent->isEmpty())
        <p class="panel mt-3 p-5 text-sm text-[var(--vg-slate)]">
            Nothing recorded yet. A row appears when the scanner finds a selection it would have bet
            and the verdict gate refuses it, at least
            {{ App\Services\Record\ShadowRecorder::MINIMUM_LEAD_MINUTES }} minutes before kickoff.
        </p>
    @else
        <div class="panel mt-3 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">The most recent shadow selections. Not published picks.</caption>
                <thead class="border-b border-[var(--vg-line)] text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                    <tr>
                        <th scope="col" class="px-4 py-2">Recorded</th>
                        <th scope="col" class="px-4 py-2">Selection</th>
                        <th scope="col" class="px-4 py-2">Gate said</th>
                        <th scope="col" class="px-4 py-2 text-right">Price</th>
                        <th scope="col" class="px-4 py-2 text-right">Edge</th>
                        <th scope="col" class="px-4 py-2">Result</th>
                        <th scope="col" class="px-4 py-2 text-right">CLV</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recent as $pick)
                        <tr class="border-b border-[var(--vg-line)] last:border-0">
                            <td class="px-4 py-3 text-[var(--vg-slate)]" data-numeric>
                                <x-local-time :at="$pick->recorded_at" format="d M H:i" />
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-semibold">{{ $pick->selection }}</span>
                                <span class="block text-xs text-[var(--vg-slate)]">{{ $pick->matchup }}</span>
                            </td>
                            {{-- Why it was declined, carried on every row. --}}
                            <td class="px-4 py-3 text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                                {{ str_replace('_', ' ', (string) $pick->market_verdict) }}
                            </td>
                            <td class="px-4 py-3 text-right" data-numeric>
                                {{ $pick->price_american > 0 ? '+' : '' }}{{ $pick->price_american }}
                            </td>
                            <td class="px-4 py-3 text-right" data-numeric>
                                {{ $pick->edge_at_price === null ? '—' : number_format($pick->edge_at_price * 100, 1).'%' }}
                            </td>
                            <td class="px-4 py-3">
                                @if ($pick->result === null)
                                    <span class="text-xs text-[var(--vg-slate)]">pending</span>
                                @else
                                    <span @class([
                                        'badge',
                                        'badge-edge' => $pick->result->status === 'won',
                                        'badge-pass' => $pick->result->status !== 'won',
                                    ])>{{ $pick->result->status }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right" data-numeric>
                                {{ $pick->result?->clv === null ? '—' : number_format($pick->result->clv * 100, 2).'%' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.admin>
