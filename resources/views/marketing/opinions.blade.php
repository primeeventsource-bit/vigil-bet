<x-layouts.marketing
    title="Opinion accuracy"
    description="Every opinion {{ config('brand.name') }} has published, how it turned out, and whether the stated confidence was true.">

    {{--
        The page that makes publishing opinions honest.

        An opinion is a weaker claim than a pick - the model's stated
        probabilities land close to the truth, not that they beat the
        market - and the only thing separating that from an assertion is
        every one of them being shown here, including the losers.

        Two rules run through it, both §0.7. No rate appears without the
        sample it came from, and an unmeasured figure shows as a dash
        rather than a zero: 0% and "nothing has been graded" look alike
        on a page and are different claims.
    --}}
    <header class="mx-auto max-w-5xl px-5 pt-16">
        <p class="text-xs uppercase tracking-[0.2em] text-[var(--vg-slate)]">Opinion accuracy</p>

        <h1 class="mt-3 font-[family-name:var(--font-display)] text-3xl font-bold sm:text-4xl">
            Every opinion we have published.
        </h1>

        <p class="mt-4 max-w-2xl text-sm leading-relaxed text-[var(--vg-slate)]">
            These are {{ config('brand.name') }}'s own opinions, recorded before kickoff and graded
            against the final score. They are <strong class="text-[var(--vg-paper)]">not proven picks</strong> —
            no market here has beaten the closing line, and this page exists so you can judge the
            opinions on their results rather than on our description of them.
        </p>

        <p class="mt-3 max-w-2xl text-sm leading-relaxed text-[var(--vg-slate)]">
            The <a href="{{ route('record') }}" class="underline">public track record</a> is a
            different and stronger thing, and the two are never added together.
        </p>
    </header>

    <section class="mx-auto mt-10 max-w-5xl px-5">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-tile
                label="Published"
                :value="number_format($summary['published'])"
                sample="recorded before kickoff" />

            <x-stat-tile
                label="Awaiting a result"
                :value="number_format($summary['awaiting'])"
                sample="game not finished or graded" />

            {{-- A rate, only ever beside its n. --}}
            <x-stat-tile
                label="Win rate"
                :value="$summary['win_rate'] === null ? '—' : number_format($summary['win_rate'] * 100, 1).'%'"
                :sample="$summary['graded'] > 0 ? 'n = '.number_format($summary['graded']) : 'nothing graded yet'" />

            <x-stat-tile
                label="Units"
                :value="$summary['units'] === null ? '—' : ($summary['units'] > 0 ? '+' : '').number_format($summary['units'], 2)"
                :sample="$summary['graded'] > 0 ? 'at one unit a selection' : 'nothing graded yet'" />
        </div>
    </section>

    <section class="mx-auto mt-5 max-w-5xl px-5">
        <div class="panel p-5">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">Closing line value</h2>

            <p class="mt-1 max-w-3xl text-xs leading-relaxed text-[var(--vg-slate)]">
                Whether the market moved toward an opinion after it was published. At a small sample
                this says more than the win rate, because it does not depend on which way a handful
                of games happened to fall. Positive means the price shortened.
            </p>

            <p class="mt-4 font-[family-name:var(--font-mono)] text-3xl font-bold" data-numeric>
                {{ $summary['mean_clv'] === null
                    ? '—'
                    : ($summary['mean_clv'] > 0 ? '+' : '').number_format($summary['mean_clv'] * 100, 2).'%' }}
            </p>

            <p class="mt-1 text-xs text-[var(--vg-slate)]">
                {{ $summary['clv_sample'] > 0
                    ? 'mean across n = '.number_format($summary['clv_sample']).' with a stored closing line'
                    : 'no closing lines stored yet' }}
            </p>
        </div>
    </section>

    {{--
        The figure this product is actually accountable for. An opinion
        published at 70% is a claim that seven in ten such opinions win,
        and this is where that claim is checked.
    --}}
    <section class="mx-auto mt-8 max-w-5xl px-5">
        <h2 class="font-[family-name:var(--font-display)] text-lg font-semibold">
            Was the confidence true?
        </h2>

        <p class="mt-2 max-w-3xl text-sm leading-relaxed text-[var(--vg-slate)]">
            An opinion published at 70% says that seven in ten like it should win. This compares what
            we said to what happened, in bands, because being right on average can hide being
            confident in the wrong direction.
        </p>

        @if ($calibration === [])
            <p class="panel mt-4 p-6 text-center text-sm text-[var(--vg-slate)]">
                Nothing has been graded yet, so there is nothing to check. This table fills in as
                games finish.
            </p>
        @else
            <div class="panel mt-4 overflow-hidden">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Stated confidence against observed win rate.</caption>
                    <thead class="border-b border-[var(--vg-line)] text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr>
                            <th scope="col" class="px-4 py-2">We said</th>
                            <th scope="col" class="px-4 py-2 text-right">Actually won</th>
                            <th scope="col" class="px-4 py-2 text-right">Sample</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($calibration as $band)
                            <tr class="border-b border-[var(--vg-line)] last:border-0">
                                <td class="px-4 py-3" data-numeric>{{ $band['stated'] }}–{{ $band['stated'] + 9 }}%</td>
                                <td class="px-4 py-3 text-right" data-numeric>
                                    {{ $band['actual'] === null ? '—' : number_format($band['actual'] * 100, 1).'%' }}
                                </td>
                                <td class="px-4 py-3 text-right text-[var(--vg-slate)]" data-numeric>
                                    n = {{ number_format($band['n']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($sports !== [])
        <section class="mx-auto mt-8 max-w-5xl px-5">
            <nav class="flex flex-wrap gap-2" aria-label="Filter by sport">
                <a href="{{ route('opinions') }}"
                   @class(['badge', 'badge-edge' => $filters['sport'] === null, 'badge-pass' => $filters['sport'] !== null])>
                    All
                </a>
                @foreach ($sports as $sport)
                    <a href="{{ route('opinions', ['sport' => $sport]) }}"
                       @class(['badge', 'badge-edge' => $filters['sport'] === $sport, 'badge-pass' => $filters['sport'] !== $sport])>
                        {{ strtoupper($sport) }}
                    </a>
                @endforeach
            </nav>
        </section>
    @endif

    <section class="mx-auto mt-6 max-w-5xl px-5 pb-20">
        <h2 class="font-[family-name:var(--font-display)] text-lg font-semibold">Every opinion</h2>

        @if ($opinions->isEmpty())
            <p class="panel mt-4 p-6 text-center text-sm text-[var(--vg-slate)]">
                Nothing published yet.
            </p>
        @else
            <div class="panel mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        Every published opinion, including the ones that lost.
                    </caption>
                    <thead class="border-b border-[var(--vg-line)] text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr>
                            <th scope="col" class="px-4 py-2">Kickoff</th>
                            <th scope="col" class="px-4 py-2">Selection</th>
                            <th scope="col" class="px-4 py-2 text-right">We said</th>
                            <th scope="col" class="px-4 py-2 text-right">Price</th>
                            <th scope="col" class="px-4 py-2">Result</th>
                            <th scope="col" class="px-4 py-2 text-right">Units</th>
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
                                <td class="px-4 py-3 text-right" data-numeric>
                                    {{ $opinion->confidencePercent() }}%
                                </td>
                                <td class="px-4 py-3 text-right" data-numeric>
                                    {{ $opinion->price_american > 0 ? '+' : '' }}{{ $opinion->price_american }}
                                </td>
                                <td class="px-4 py-3">
                                    @if ($opinion->result === null)
                                        <span class="text-xs text-[var(--vg-slate)]">pending</span>
                                    @else
                                        {{-- The losers are shown, which is the point. --}}
                                        <span @class([
                                            'badge',
                                            'badge-edge' => $opinion->result->won(),
                                            'badge-pass' => ! $opinion->result->won(),
                                        ])>{{ $opinion->result->status }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right" data-numeric>
                                    {{ $opinion->result === null
                                        ? '—'
                                        : ($opinion->result->units > 0 ? '+' : '').number_format($opinion->result->units, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $opinions->links() }}</div>
        @endif
    </section>
</x-layouts.marketing>
