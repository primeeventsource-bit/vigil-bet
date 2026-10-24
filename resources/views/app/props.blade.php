<x-layouts.app title="Props">
    <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Player Props</h1>

    {{--
        The honest framing, up front. Props are collected and displayed,
        not modelled - Edge Lab ingests them into a column the model
        pipeline cannot read, and the schema enforces that rather than a
        convention. So there is no edge figure on this page and there must
        never appear to be one.
    --}}
    <p class="mt-1 max-w-2xl text-sm text-[var(--vg-slate)]">
        Props are <strong>not modelled</strong>. There is no probability, no edge and no verdict
        here, because the engine does not price them. What this page is for is line shopping:
        the same player at the same number, priced differently across books, is a real advantage
        that needs no model at all.
    </p>

    @if ($sports->isNotEmpty())
        <div class="mt-5 flex flex-wrap gap-1.5">
            <a href="{{ route('props') }}" @class([
                'rounded-md px-2.5 py-1 text-xs font-semibold transition',
                'bg-[color-mix(in_srgb,var(--vg-paper)_12%,transparent)] text-[var(--vg-paper)]' => ! $sport,
                'text-[var(--vg-slate)] hover:text-[var(--vg-paper)]' => (bool) $sport,
            ])>All</a>

            @foreach ($sports as $code)
                <a href="{{ route('props', ['sport' => $code]) }}" @class([
                    'rounded-md px-2.5 py-1 text-xs font-semibold uppercase transition',
                    'bg-[color-mix(in_srgb,var(--vg-paper)_12%,transparent)] text-[var(--vg-paper)]' => $sport === $code,
                    'text-[var(--vg-slate)] hover:text-[var(--vg-paper)]' => $sport !== $code,
                ])>{{ $code }}</a>
            @endforeach
        </div>
    @endif

    <section class="panel mt-5 overflow-hidden">
        <h2 class="border-b border-[var(--vg-line)] px-5 py-3.5 font-[family-name:var(--font-display)] font-semibold">
            Best price by player
        </h2>

        @if ($groups->isEmpty())
            {{--
                Empty because the display mirror only holds games somebody
                has opened. That is the licence boundary rather than a
                gap, and saying so beats implying coverage the product
                does not have.
            --}}
            <div class="px-5 py-10 text-center">
                <p class="text-sm text-[var(--vg-slate)]">No prop quotes stored yet.</p>
                <p class="mx-auto mt-2 max-w-md text-xs leading-relaxed text-[var(--vg-slate)]">
                    Props are stored for games somebody has opened, not pulled for every league in
                    advance. A board that scanned every player in every sport would be the bulk-odds
                    product {{ config('brand.name') }}'s data licence does not permit.
                </p>
            </div>
        @else
            <div class="w-full overflow-x-auto">
                <table class="w-full min-w-[800px] text-left text-sm">
                    <caption class="sr-only">Player prop prices across books</caption>
                    <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr class="border-b border-[var(--vg-line)]">
                            <th scope="col" class="px-5 py-2.5 font-medium">Player</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Market</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Line</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Best</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Price</th>
                            <th scope="col" class="px-5 py-2.5 text-right font-medium">Worth shopping</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($groups as $row)
                            <tr class="border-b border-[var(--vg-line)] last:border-0">
                                <td class="px-5 py-3">
                                    <span class="font-medium">{{ $row['player'] }}</span>
                                    <span class="block text-xs text-[var(--vg-slate)]">{{ $row['matchup'] }}</span>
                                </td>
                                <td class="px-3 py-3 text-xs">
                                    {{ $row['market'] }}
                                    @if ($row['side'])
                                        <span class="block uppercase text-[var(--vg-slate)]">{{ $row['side'] }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                    {{ $row['line'] ?? '—' }}
                                </td>
                                <td class="px-3 py-3 text-xs">{{ $row['best_book'] }}</td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums text-[var(--vg-edge)]" data-numeric>
                                    {{ $row['best_price'] > 0 ? '+' : '' }}{{ $row['best_price'] }}
                                </td>
                                <td class="px-5 py-3 text-right text-xs">
                                    @if ($row['spread'] === null)
                                        <span class="text-[var(--vg-slate)]">one book only</span>
                                    @else
                                        <span class="font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                            {{ $row['spread'] }} pts
                                        </span>
                                        <span class="block text-[var(--vg-slate)]" data-numeric>
                                            across {{ $row['books']->count() }} books
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="border-t border-[var(--vg-line)] px-5 py-3 text-xs text-[var(--vg-slate)]">
                Prices are the most recent stored quote per book, not a live feed. Check the book
                before you stake.
            </p>
        @endif
    </section>
</x-layouts.app>
