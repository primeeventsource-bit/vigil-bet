<x-layouts.app title="Bankroll">
    <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Bankroll</h1>
    <p class="mt-1 text-sm text-[var(--vg-slate)]">
        Staking, and the limits that stop it. {{ config('brand.name') }} holds no money —
        these are your own notes about what is at your books.
    </p>

    @if (session('status'))
        <p class="mt-4 rounded-lg border border-[color-mix(in_srgb,var(--vg-edge)_40%,transparent)] bg-[color-mix(in_srgb,var(--vg-edge)_10%,transparent)] px-4 py-3 text-sm">
            {{ session('status') }}
        </p>
    @endif

    @if ($blocked)
        <p class="mt-4 rounded-lg border border-[var(--vg-loss)] px-4 py-3 text-sm">
            {{ $blocked }}
        </p>
    @endif

    <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-tile label="Balance"
                     :value="'$'.number_format($balanceCents / 100, 2)"
                     :sample="'from $'.number_format($settings->starting_cents / 100, 2).' starting'"
                     :tone="$balanceCents >= $settings->starting_cents ? 'edge' : 'loss'" />

        <x-stat-tile label="Down today"
                     :value="'$'.number_format($lossTodayCents / 100, 2)"
                     :sample="$settings->daily_loss_limit_cents === null
                        ? 'no daily limit set'
                        : 'limit $'.number_format($settings->daily_loss_limit_cents / 100, 2)"
                     :tone="$lossTodayCents > 0 ? 'loss' : 'ink'" />

        <x-stat-tile label="Down this week"
                     :value="'$'.number_format($lossWeekCents / 100, 2)"
                     :sample="$settings->weekly_loss_limit_cents === null
                        ? 'no weekly limit set'
                        : 'limit $'.number_format($settings->weekly_loss_limit_cents / 100, 2)"
                     :tone="$lossWeekCents > 0 ? 'loss' : 'ink'" />

        <x-stat-tile label="Max stake"
                     :value="$settings->max_stake_pct.'%'"
                     :sample="'$'.number_format($balanceCents * $settings->max_stake_pct / 10000, 2).' at this balance'" />
    </section>

    {{-- Staking rules --}}
    <section class="panel mt-5 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Staking rules</h2>

        <p class="mt-2 text-xs leading-relaxed text-[var(--vg-slate)]">
            Kelly sizing assumes the model's probability is exactly right. It never is, so
            {{ config('brand.name') }} stakes a fraction of what Kelly says and applies a flat
            ceiling on top. The ceiling is the part that matters: Kelly on an over-confident
            probability recommends a stake that ruins somebody.
        </p>

        @if ($settings->hasPendingChange())
            <p class="mt-3 rounded-lg border border-dashed border-[var(--vg-line)] px-4 py-3 text-xs text-[var(--vg-slate)]">
                A looser limit is waiting and takes effect
                {{ $settings->pending_limits_effective_on->toFormattedDateString() }}.
                Tightening it again happens straight away.
            </p>
        @endif

        <form method="POST" action="{{ route('bankroll.update') }}" class="mt-4 grid gap-3 sm:grid-cols-2">
            @csrf

            <label class="block text-sm">
                <span class="text-[var(--vg-slate)]">Kelly fraction</span>
                <input name="kelly_scale" type="number" step="0.05" min="0.05" max="0.5" required
                       value="{{ old('kelly_scale', $settings->kelly_scale) }}"
                       class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                <span class="mt-1 block text-[11px] text-[var(--vg-slate)]">
                    Quarter Kelly by default. Capped below full on purpose.
                </span>
            </label>

            <label class="block text-sm">
                <span class="text-[var(--vg-slate)]">Maximum stake (% of bankroll)</span>
                <input name="max_stake_pct" type="number" step="0.1" min="0.1" max="10" required
                       value="{{ old('max_stake_pct', $settings->max_stake_pct) }}"
                       class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
            </label>

            <label class="block text-sm">
                <span class="text-[var(--vg-slate)]">Daily loss limit ($)</span>
                <input name="daily_loss_limit" type="number" step="0.01" min="0"
                       value="{{ old('daily_loss_limit', $settings->daily_loss_limit_cents === null ? '' : $settings->daily_loss_limit_cents / 100) }}"
                       class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
            </label>

            <label class="block text-sm">
                <span class="text-[var(--vg-slate)]">Weekly loss limit ($)</span>
                <input name="weekly_loss_limit" type="number" step="0.01" min="0"
                       value="{{ old('weekly_loss_limit', $settings->weekly_loss_limit_cents === null ? '' : $settings->weekly_loss_limit_cents / 100) }}"
                       class="mt-1 w-full rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
            </label>

            <div class="sm:col-span-2">
                <button type="submit" class="rounded-lg bg-[var(--vg-green)] px-4 py-2.5 font-bold text-[var(--vg-night)]">
                    Save
                </button>
                {{--
                    The sentence that explains why there is no "lift it
                    now" button anywhere on this page.
                --}}
                <p class="mt-2 text-xs text-[var(--vg-slate)]">
                    A tighter limit applies at once. A looser one applies tomorrow — a limit you can
                    lift mid-session is not a limit.
                </p>
            </div>
        </form>
    </section>

    {{-- Money in and out --}}
    <section class="panel mt-5 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Record money</h2>

        <form method="POST" action="{{ route('bankroll.funds') }}" class="mt-4 flex flex-wrap items-end gap-3">
            @csrf

            <label class="block text-sm">
                <span class="text-[var(--vg-slate)]">What</span>
                <select name="kind" required
                        class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                    <option value="starting">Set starting bankroll</option>
                    <option value="deposit">Deposit</option>
                    <option value="withdrawal">Withdrawal</option>
                </select>
            </label>

            <label class="block text-sm">
                <span class="text-[var(--vg-slate)]">Amount ($)</span>
                <input name="amount" type="number" step="0.01" min="0.01" required
                       class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
            </label>

            <button type="submit" class="rounded-lg border border-[var(--vg-line)] px-4 py-2.5 text-sm font-semibold">
                Record
            </button>
        </form>
    </section>

    {{-- The ledger --}}
    <section class="panel mt-5 overflow-hidden">
        <h2 class="border-b border-[var(--vg-line)] px-5 py-3.5 font-[family-name:var(--font-display)] font-semibold">
            Ledger
        </h2>

        @if ($entries->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-[var(--vg-slate)]">
                Nothing recorded yet. Set a starting bankroll above to see suggested stakes.
            </p>
        @else
            <ul class="divide-y divide-[var(--vg-line)] text-sm">
                @foreach ($entries as $entry)
                    <li class="flex items-baseline justify-between gap-4 px-5 py-3">
                        <span>
                            {{ ucfirst($entry->kind) }}
                            @if ($entry->reason)
                                <span class="block text-xs text-[var(--vg-slate)]">{{ $entry->reason }}</span>
                            @endif
                        </span>
                        <span @class([
                            'shrink-0 font-[family-name:var(--font-mono)] tabular-nums',
                            'text-[var(--vg-edge)]' => $entry->amount_cents > 0,
                            'text-[var(--vg-loss)]' => $entry->amount_cents < 0,
                        ]) data-numeric>
                            {{ sprintf('%+.2f', $entry->amount_cents / 100) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.app>
