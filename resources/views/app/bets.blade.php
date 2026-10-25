<x-layouts.app title="My Bets">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">My Bets</h1>
            <p class="mt-1 text-sm text-[var(--vg-slate)]">
                Your own record. {{ config('brand.name') }} holds no money and places nothing —
                you bet at your book and log it here.
            </p>
        </div>

        <a href="{{ route('bets.export') }}"
           class="rounded-lg border border-[var(--vg-line)] px-3 py-2 text-sm font-semibold text-[var(--vg-slate)] hover:text-[var(--vg-paper)]">
            Export CSV
        </a>
    </div>

    @if (session('status'))
        <p class="mt-4 rounded-lg border border-[color-mix(in_srgb,var(--vg-edge)_40%,transparent)] bg-[color-mix(in_srgb,var(--vg-edge)_10%,transparent)] px-4 py-3 text-sm">
            {{ session('status') }}
        </p>
    @endif

    @if ($blocked)
        {{--
            The loss limit, stated and not negotiable. There is no button
            here that lifts it, by design: §8 says "no override", and a
            prompt offering one would appear at exactly the wrong moment.
        --}}
        <p class="mt-4 rounded-lg border border-[var(--vg-loss)] px-4 py-3 text-sm">
            {{ $blocked }}
        </p>
    @endif

    @error('stake')
        <p class="mt-4 rounded-lg border border-[var(--vg-loss)] px-4 py-3 text-sm">{{ $message }}</p>
    @enderror

    {{-- The record. Every figure with its sample, or no figure at all. --}}
    <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-tile label="Settled" :value="$record['settled'] ?: null"
                    :sample="$record['pending'] ? $record['pending'].' still running' : 'none running'" />

        <x-stat-tile label="Win rate"
                    :value="$record['win_rate'] === null ? null : round($record['win_rate'] * 100).'%'"
                    :sample="'n = '.($record['won'] + $record['lost'])" />

        @if ($showRoi)
            <x-stat-tile label="ROI"
                        :value="$record['roi'] === null ? null : sprintf('%+.1f%%', $record['roi'] * 100)"
                        :sample="$record['units'] === null ? 'no settled bets' : sprintf('%+.2f units', $record['units'])" />
        @else
            <x-stat-tile label="ROI" :value="null" locked
                        sample="Comes with a paid plan" :href="route('upgrade', ['feature' => 'bets.roi'])" />
        @endif

        @if ($showClv)
            {{--
                Closing line value: whether the market came to agree with
                the bet. The most honest measure there is, because it does
                not care whether the bet won.
            --}}
            <x-stat-tile label="Closing line value"
                        :value="$record['clv'] === null ? null : sprintf('%+.2f%%', $record['clv'] * 100)"
                        :sample="'n = '.$record['clv_sample']" />
        @else
            <x-stat-tile label="Closing line value" :value="null" locked
                        sample="Comes with Pro" :href="route('upgrade', ['feature' => 'bets.clv'])" />
        @endif
    </section>

    @if ($curve['series'] !== [])
        <section class="panel mt-5 p-5">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">Profit, bet by bet</h2>
            <p class="mt-1 text-xs text-[var(--vg-slate)]">
                Cumulative profit in dollars over {{ count($curve['series']) }} settled
                {{ Str::plural('bet', count($curve['series'])) }}. Starts at zero, not at your bankroll.
            </p>
            <x-chart.area :labels="$curve['labels']" :points="$curve['series']" label="Cumulative profit in dollars" class="mt-4" />
        </section>
    @endif

    {{-- Log one by hand --}}
    <section class="panel mt-5 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Log a bet</h2>
        <p class="mt-1 text-xs text-[var(--vg-slate)]">
            A bet you placed at your own book. Logging it here places nothing.
        </p>

        @if ($trackedLimit !== null)
            <p class="mt-2 text-xs text-[var(--vg-slate)]">
                Your plan tracks <strong data-numeric>{{ $tracked }}</strong> of
                <strong data-numeric>{{ $trackedLimit }}</strong> bets.
                <a class="text-[var(--vg-blue)] underline"
                   href="{{ route('upgrade', ['feature' => 'bets.roi']) }}">A paid plan tracks every bet</a>.
            </p>
        @endif

        <form method="POST" action="{{ route('bets.store') }}" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @csrf

            <label class="block text-sm">
                <span class="text-[var(--vg-slate)]">Sport</span>
                <input name="sport" value="{{ old('sport') }}" required maxlength="20"
                       class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
            </label>

            <label class="block text-sm">
                <span class="text-[var(--vg-slate)]">Market</span>
                <select name="market" required
                        class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                    <option value="h2h">Moneyline</option>
                    <option value="spreads">Spread</option>
                    <option value="totals">Total</option>
                </select>
            </label>

            <label class="block text-sm">
                <span class="text-[var(--vg-slate)]">Selection</span>
                <input name="selection" value="{{ old('selection') }}" required maxlength="120"
                       class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
            </label>

            <label class="block text-sm">
                <span class="text-[var(--vg-slate)]">Side</span>
                <select name="side"
                        class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                    <option value="">&mdash;</option>
                    <option value="home">Home</option>
                    <option value="away">Away</option>
                    <option value="over">Over</option>
                    <option value="under">Under</option>
                </select>
            </label>

            <label class="block text-sm">
                <span class="text-[var(--vg-slate)]">Line</span>
                <input name="line" value="{{ old('line') }}" type="number" step="0.5"
                       class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
            </label>

            <label class="block text-sm">
                <span class="text-[var(--vg-slate)]">Price (American)</span>
                <input name="price_american" value="{{ old('price_american') }}" type="number" required
                       class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
            </label>

            <label class="block text-sm">
                <span class="text-[var(--vg-slate)]">Book</span>
                <input name="book_title" value="{{ old('book_title') }}" maxlength="60"
                       class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
            </label>

            <label class="block text-sm">
                <span class="text-[var(--vg-slate)]">Stake ($)</span>
                <input name="stake" value="{{ old('stake') }}" type="number" step="0.01" min="0.01" required
                       class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
            </label>

            <div class="sm:col-span-2 lg:col-span-4">
                <button type="submit" @disabled((bool) $blocked)
                        class="rounded-lg bg-[var(--vg-paper)] px-4 py-2.5 font-bold text-[var(--vg-night)] disabled:opacity-40">
                    Log it
                </button>
            </div>
        </form>
    </section>

    {{-- The bets themselves --}}
    <section class="panel mt-5 overflow-hidden">
        <h2 class="border-b border-[var(--vg-line)] px-5 py-3.5 font-[family-name:var(--font-display)] font-semibold">
            Every bet
        </h2>

        @if ($bets->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-[var(--vg-slate)]">
                Nothing logged yet. Log a bet above, or tap <strong>Bet</strong> on a pick.
            </p>
        @else
            {{--
                Cards below md, table from md up. Six columns of numbers do
                not fit a phone, and a horizontal scroll nobody finds is
                not a solution.
            --}}
            <ul class="divide-y divide-[var(--vg-line)] md:hidden">
                @foreach ($bets as $bet)
                    <li class="px-5 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium">{{ $bet->selection }}</p>
                                <p class="mt-0.5 text-xs uppercase text-[var(--vg-slate)]">
                                    {{ $bet->sport }} &middot; {{ $bet->market }}
                                    @if ($bet->line !== null) &middot; {{ $bet->line }} @endif
                                </p>
                            </div>
                            <x-app.bet-status :bet="$bet" />
                        </div>

                        <dl class="mt-3 grid grid-cols-3 gap-2 text-xs">
                            <div>
                                <dt class="text-[var(--vg-slate)]">Price</dt>
                                <dd class="font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                    {{ $bet->price_american > 0 ? '+' : '' }}{{ $bet->price_american }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-[var(--vg-slate)]">Stake</dt>
                                <dd class="font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                    ${{ number_format($bet->stake_cents / 100, 2) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-[var(--vg-slate)]">Profit</dt>
                                <dd class="font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                    {{ $bet->profitCents() === null ? '—' : sprintf('%+.2f', $bet->profitCents() / 100) }}
                                </dd>
                            </div>
                        </dl>
                    </li>
                @endforeach
            </ul>

            <div class="hidden w-full overflow-x-auto md:block">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <caption class="sr-only">Your logged bets</caption>
                    <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr class="border-b border-[var(--vg-line)]">
                            <th scope="col" class="px-5 py-2.5 font-medium">Placed</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Selection</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Price</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Stake</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Result</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Profit</th>
                            @if ($showClv)
                                <th scope="col" class="px-5 py-2.5 text-right font-medium">CLV</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bets as $bet)
                            <tr class="border-b border-[var(--vg-line)] last:border-0">
                                <td class="px-5 py-3 text-xs text-[var(--vg-slate)]">
                                    <x-local-time :at="$bet->placed_at" format="j M" />
                                </td>
                                <td class="px-3 py-3">
                                    <span class="font-medium">{{ $bet->selection }}</span>
                                    <span class="block text-xs uppercase text-[var(--vg-slate)]">
                                        {{ $bet->sport }} &middot; {{ $bet->market }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                    {{ $bet->price_american > 0 ? '+' : '' }}{{ $bet->price_american }}
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                    ${{ number_format($bet->stake_cents / 100, 2) }}
                                </td>
                                <td class="px-3 py-3"><x-app.bet-status :bet="$bet" /></td>
                                <td @class([
                                    'px-3 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums',
                                    'text-[var(--vg-edge)]' => ($bet->profitCents() ?? 0) > 0,
                                    'text-[var(--vg-loss)]' => ($bet->profitCents() ?? 0) < 0,
                                ]) data-numeric>
                                    {{ $bet->profitCents() === null ? '—' : sprintf('%+.2f', $bet->profitCents() / 100) }}
                                </td>
                                @if ($showClv)
                                    <td class="px-5 py-3 text-right font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                        {{ $bet->clv === null ? '—' : sprintf('%+.2f%%', $bet->clv * 100) }}
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4">{{ $bets->links() }}</div>
        @endif
    </section>
</x-layouts.app>
