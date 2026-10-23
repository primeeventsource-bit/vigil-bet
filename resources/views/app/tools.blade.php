<x-layouts.app title="Tools">
    <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Tools</h1>
    <p class="mt-1 max-w-2xl text-sm text-[var(--vg-slate)]">
        Arbitrage and middles, found in prices already stored. These need no model — they are
        arithmetic on two numbers from two different books.
    </p>

    {{--
        The caveat that makes the page honest. These are stored quotes,
        not a live feed, and an arb that existed four minutes ago usually
        does not exist now. Stated once, prominently, rather than in small
        print under each row.
    --}}
    <p class="mt-4 rounded-lg border border-dashed border-[var(--vg-line)] px-4 py-3 text-xs leading-relaxed text-[var(--vg-slate)]">
        These are the most recent <strong>stored</strong> quotes, not live prices, and only for
        games somebody has already opened — {{ config('brand.name') }}'s data licence does not
        permit scanning every league in advance. An arbitrage that existed a few minutes ago has
        usually gone. Always check both books before staking either side.
    </p>

    {{-- Arbitrage --}}
    <section class="panel mt-5 overflow-hidden">
        <h2 class="border-b border-[var(--vg-line)] px-5 py-3.5 font-[family-name:var(--font-display)] font-semibold">
            Arbitrage
        </h2>

        @if ($arbitrage->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-[var(--vg-slate)]">
                None in the stored quotes. That is the normal state of affairs — books price
                against each other, and a standing arb would mean one of them had made a mistake
                nobody had taken yet.
            </p>
        @else
            <div class="w-full overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <caption class="sr-only">Arbitrage opportunities in stored quotes</caption>
                    <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr class="border-b border-[var(--vg-line)]">
                            <th scope="col" class="px-5 py-2.5 font-medium">Game</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Side one</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Side two</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Profit</th>
                            <th scope="col" class="px-5 py-2.5 text-right font-medium">Quoted</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($arbitrage as $arb)
                            <tr class="border-b border-[var(--vg-line)] last:border-0">
                                <td class="px-5 py-3">
                                    <span class="font-medium">{{ $arb['matchup'] ?? 'Game '.$arb['event_id'] }}</span>
                                    <span class="block text-xs uppercase text-[var(--vg-slate)]">{{ $arb['market'] }}</span>
                                </td>
                                <td class="px-3 py-3 text-xs">
                                    {{ $arb['a']->book_title ?? $arb['a']->book }}
                                    <span class="block font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                        {{ $arb['a']->side }}
                                        {{ $arb['a']->price_american > 0 ? '+' : '' }}{{ $arb['a']->price_american }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-xs">
                                    {{ $arb['b']->book_title ?? $arb['b']->book }}
                                    <span class="block font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                        {{ $arb['b']->side }}
                                        {{ $arb['b']->price_american > 0 ? '+' : '' }}{{ $arb['b']->price_american }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums text-[var(--vg-edge)]" data-numeric>
                                    {{ sprintf('%+.2f%%', $arb['profit'] * 100) }}
                                </td>
                                <td class="px-5 py-3 text-right text-xs text-[var(--vg-slate)]">
                                    {{ $arb['as_of']->diffForHumans() }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Middles --}}
    <section class="panel mt-5 overflow-hidden">
        <h2 class="border-b border-[var(--vg-line)] px-5 py-3.5 font-[family-name:var(--font-display)] font-semibold">
            Middles
        </h2>

        <p class="border-b border-[var(--vg-line)] px-5 py-2.5 text-xs text-[var(--vg-slate)]">
            A middle needs the result to land inside the gap. It is not free money — when it
            misses, one side wins and the other loses, and the cost column is what that costs you.
        </p>

        @if ($middles->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-[var(--vg-slate)]">
                None in the stored quotes.
            </p>
        @else
            <div class="w-full overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <caption class="sr-only">Middle opportunities in stored quotes</caption>
                    <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr class="border-b border-[var(--vg-line)]">
                            <th scope="col" class="px-5 py-2.5 font-medium">Game</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Side one</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Side two</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Gap</th>
                            <th scope="col" class="px-5 py-2.5 text-right font-medium">Cost if it misses</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($middles as $middle)
                            <tr class="border-b border-[var(--vg-line)] last:border-0">
                                <td class="px-5 py-3">
                                    <span class="font-medium">{{ $middle['matchup'] ?? 'Game '.$middle['event_id'] }}</span>
                                    <span class="block text-xs uppercase text-[var(--vg-slate)]">{{ $middle['market'] }}</span>
                                </td>
                                <td class="px-3 py-3 text-xs">
                                    {{ $middle['a']->book_title ?? $middle['a']->book }}
                                    <span class="block font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                        {{ $middle['a']->side }} {{ $middle['a']->line }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-xs">
                                    {{ $middle['b']->book_title ?? $middle['b']->book }}
                                    <span class="block font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                        {{ $middle['b']->side }} {{ $middle['b']->line }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums text-[var(--vg-edge)]" data-numeric>
                                    {{ $middle['width'] }} pts
                                </td>
                                <td class="px-5 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                    {{ sprintf('%.2f%%', $middle['cost'] * 100) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
