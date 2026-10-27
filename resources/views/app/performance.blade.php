@php
    use App\Support\Money;
@endphp

<x-layouts.app title="Results and performance">
    <header>
        <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Results and performance</h1>
        <p class="mt-2 max-w-3xl text-sm text-[var(--vg-slate)]">
            Two records side by side: what the engine has published, and what you have logged.
        </p>
    </header>

    {{--
        The distinction between the two, said once and early.

        These are easy to confuse and must not be. One is the product's
        claim about itself, graded in public. The other is a log of bets
        a customer typed in, which nothing here can verify.
    --}}
    <section class="panel mt-5 p-5">
        <h2 class="text-sm font-bold">Which number is which</h2>
        <p class="mt-1 max-w-3xl text-xs text-[var(--vg-muted)]">
            The engine's record is every opinion it published before kickoff, graded against the
            result whether it won or lost. Your record is the bets you entered yourself &mdash;
            the product has no connection to any sportsbook and cannot check a single one of them,
            so treat your own figures as a notebook rather than a statement.
        </p>
    </section>

    {{-- ----------------------------------------------------------------
         Your record
    ----------------------------------------------------------------- --}}
    <section class="mt-6">
        <h2 class="font-[family-name:var(--font-display)] text-lg font-semibold">Your record</h2>

        @if (($record['settled'] ?? 0) === 0)
            <div class="panel mt-3 p-8 text-center">
                <p class="font-semibold">Nothing settled yet.</p>
                <p class="mt-1 text-sm text-[var(--vg-muted)]">
                    @if (($record['pending'] ?? 0) > 0)
                        {{ $record['pending'] }} bet(s) are still open. Figures appear once they are graded.
                    @else
                        Log a bet and its result, and the figures build from there.
                    @endif
                </p>
                <a href="{{ route('bets') }}"
                   class="mt-4 inline-block rounded-lg border border-[var(--vg-line)] px-4 py-2 text-sm font-semibold">
                    Go to My Bets
                </a>
            </div>
        @else
            <div class="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-stat-tile label="Settled" :value="(string) $record['settled']"
                             :sample="($record['pending'] ?? 0).' still open'" />

                <x-stat-tile label="Win rate"
                             :value="$record['win_rate'] === null ? null : number_format($record['win_rate'] * 100, 1).'%'"
                             :sample="'n = '.(($record['won'] ?? 0) + ($record['lost'] ?? 0))" />

                <x-stat-tile label="Units"
                             :value="$record['units'] === null ? null : number_format($record['units'], 2)"
                             :tone="($record['units'] ?? 0) >= 0 ? 'edge' : 'loss'"
                             sample="at one unit a bet" />

                {{--
                    Shown even when the profit is negative, and
                    especially then. Closing line value says whether a
                    bet beat the number the market settled on, which
                    predicts a long run better than a short one's
                    profit does.
                --}}
                <x-stat-tile label="Closing line value"
                             :locked="! $showClv"
                             :value="$record['clv'] === null ? null : number_format($record['clv'] * 100, 2).'%'"
                             :sample="'n = '.($record['clv_sample'] ?? 0)"
                             :href="$showClv ? null : route('upgrade', ['feature' => 'bets.clv'])" />
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <x-stat-tile label="Staked" :value="Money::format((int) ($record['staked_cents'] ?? 0))"
                             sample="across settled bets" />
                <x-stat-tile label="Returned" :value="Money::format((int) ($record['returned_cents'] ?? 0))"
                             sample="stake included" />
                <x-stat-tile label="Profit"
                             :value="$record['profit_cents'] === null ? null : Money::format((int) $record['profit_cents'], signed: true)"
                             :tone="($record['profit_cents'] ?? 0) >= 0 ? 'edge' : 'loss'"
                             sample="returned less staked" />
            </div>

            @if ($curve !== [])
                <section class="panel mt-4 p-5">
                    <h3 class="text-sm font-bold">Cumulative units</h3>
                    <p class="mt-1 text-xs text-[var(--vg-muted)]">
                        In the order your bets settled. Drawn in silver, not green &mdash; the green
                        in this product means a proven edge and a bet log is not one.
                    </p>
                    <div class="mt-4">
                        <x-chart.area :points="$curve" label="Cumulative units from your settled bets" />
                    </div>
                </section>
            @endif

            @if ($bySport->isNotEmpty())
                <section class="panel mt-4 overflow-hidden">
                    <div class="px-5 pt-5">
                        <h3 class="text-sm font-bold">By league</h3>
                        <p class="mt-1 text-xs text-[var(--vg-muted)]">
                            Being down overall and being down entirely in one league are different
                            facts, and only the second one tells you what to change.
                        </p>
                    </div>

                    <div class="mt-4 w-full overflow-x-auto">
                        <table class="w-full min-w-[560px] text-left text-sm">
                            <caption class="sr-only">Your settled bets by league</caption>
                            <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                                <tr class="border-b border-[var(--vg-line)]">
                                    <th scope="col" class="px-5 py-2.5 font-medium">League</th>
                                    <th scope="col" class="px-3 py-2.5 text-right font-medium">Settled</th>
                                    <th scope="col" class="px-3 py-2.5 text-right font-medium">Won</th>
                                    <th scope="col" class="px-3 py-2.5 text-right font-medium">Lost</th>
                                    <th scope="col" class="px-5 py-2.5 text-right font-medium">Profit</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($bySport as $row)
                                    @php($profit = (int) $row->returned - (int) $row->staked)
                                    <tr class="border-b border-[var(--vg-line)] last:border-0">
                                        <td class="px-5 py-3 font-[family-name:var(--font-mono)] text-xs uppercase">
                                            {{ $row->sport }}
                                        </td>
                                        <td class="px-3 py-3 text-right text-xs">{{ $row->n }}</td>
                                        <td class="px-3 py-3 text-right text-xs">{{ $row->won }}</td>
                                        <td class="px-3 py-3 text-right text-xs">{{ $row->lost }}</td>
                                        <td @class([
                                            'px-5 py-3 text-right font-[family-name:var(--font-mono)] text-xs font-bold',
                                            'text-[var(--vg-edge)]' => $profit > 0,
                                            'text-[var(--vg-loss)]' => $profit < 0,
                                        ])>{{ Money::format($profit, signed: true) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         The engine's record
    ----------------------------------------------------------------- --}}
    <section class="mt-8">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-[family-name:var(--font-display)] text-lg font-semibold">The engine's record</h2>
            <a href="{{ route('opinions') }}" class="text-xs text-[var(--vg-slate)] underline hover:text-[var(--vg-paper)]">
                The public version &rarr;
            </a>
        </div>

        <p class="mt-1 max-w-3xl text-xs text-[var(--vg-muted)]">
            Every opinion published before kickoff, graded whichever way it went. Nothing is
            removed from this once it is published.
        </p>

        @if (($engine['graded'] ?? 0) === 0)
            <div class="panel mt-3 p-8 text-center">
                <p class="font-semibold">Nothing graded yet.</p>
                <p class="mt-1 text-sm text-[var(--vg-muted)]">
                    {{ $engine['published'] ?? 0 }} published,
                    {{ $engine['awaiting'] ?? 0 }} still awaiting a result.
                    A rate with no sample behind it would not be a rate.
                </p>
            </div>
        @else
            <div class="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-stat-tile label="Published" :value="(string) $engine['published']"
                             :sample="($engine['awaiting'] ?? 0).' awaiting a result'" />

                <x-stat-tile label="Win rate"
                             :value="$engine['win_rate'] === null ? null : number_format($engine['win_rate'] * 100, 1).'%'"
                             :sample="'n = '.$engine['graded']" />

                <x-stat-tile label="Units"
                             :value="$engine['units'] === null ? null : number_format($engine['units'], 2)"
                             :tone="($engine['units'] ?? 0) >= 0 ? 'edge' : 'loss'"
                             sample="at one unit an opinion" />

                <x-stat-tile label="Mean CLV"
                             :value="$engine['mean_clv'] === null ? null : number_format($engine['mean_clv'] * 100, 2).'%'"
                             :sample="'n = '.($engine['clv_sample'] ?? 0)" />
            </div>
        @endif
    </section>
</x-layouts.app>
