<x-layouts.admin title="Overview">
    {{-- Customers --}}
    <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-tile label="Accounts" :value="$customers ?: null"
                     :sample="$verified.' confirmed'" />

        <x-stat-tile label="Paying" :value="$subscribers ?: null"
                     :sample="$pastDue ? $pastDue.' past due' : 'none past due'"
                     :tone="$subscribers > 0 ? 'edge' : 'ink'" />

        {{--
            Charged, not "revenue". This is the sum of money that
            actually moved, read from billing_events - not subscriber
            count times list price, which is what a product hopes it is
            charging and diverges the first time a payment fails.
        --}}
        <x-stat-tile label="Charged, all time"
                     :value="$chargedCents ? '$'.number_format($chargedCents / 100, 2) : null"
                     :sample="$refundedCents
                        ? '$'.number_format($refundedCents / 100, 2).' refunded'
                        : 'nothing refunded'" />

        <x-stat-tile label="AI spend this month"
                     :value="'$'.number_format($aiSpendMonth / 100000, 2)"
                     :sample="$aiCalls === 0
                        ? 'no calls yet'
                        : $aiRejected.' of '.$aiCalls.' rejected'"
                     :tone="$aiRejected > 0 ? 'loss' : 'ink'" />
    </section>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        {{-- Plans --}}
        <section class="panel p-5">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">Plans</h2>

            @if ($byTier->isEmpty())
                <p class="mt-2 text-sm text-[var(--vg-slate)]">
                    No subscription rows yet. Every account is on the free plan by default.
                </p>
            @else
                <ul class="mt-4 space-y-2">
                    @foreach ($byTier as $tier => $total)
                        <x-chart.bar-row :label="$tier" :value="$total" :max="$byTier->max()" suffix="" />
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- The record --}}
        <section class="panel p-5">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">Public record</h2>

            @if ($record['published'] === 0)
                <p class="mt-2 text-sm text-[var(--vg-slate)]">
                    Nothing published. No market has cleared the gate, so there is nothing to show
                    and nothing being sold from.
                </p>
            @else
                <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-[var(--vg-slate)]">Published</dt>
                        <dd class="font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                            {{ $record['published'] }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[var(--vg-slate)]">Settled</dt>
                        <dd class="font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                            {{ $record['settled'] }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[var(--vg-slate)]">Units</dt>
                        <dd class="font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                            {{ $record['units'] === null ? '—' : sprintf('%+.2f', $record['units']) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[var(--vg-slate)]">CLV</dt>
                        <dd class="font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                            {{ $record['clv'] === null ? '—' : sprintf('%+.2f%%', $record['clv'] * 100) }}
                        </dd>
                    </div>
                </dl>
            @endif

            <p class="mt-4 text-xs text-[var(--vg-slate)]">
                <a class="text-[var(--vg-blue)] underline" href="{{ route('admin.picks') }}">The ledger</a>
                &middot;
                <a class="text-[var(--vg-blue)] underline" href="{{ route('record') }}">The public page</a>
            </p>
        </section>
    </div>

    {{-- Feeds --}}
    <section class="panel mt-5 p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">Edge Lab feeds</h2>
            <a class="text-xs text-[var(--vg-blue)] underline" href="{{ route('admin.sync-health') }}">
                Full sync health
            </a>
        </div>

        <ul class="mt-4 grid gap-3 sm:grid-cols-3">
            @foreach ($syncHealth as $feed => $state)
                <li class="rounded-lg border border-[var(--vg-line)] px-4 py-3">
                    <p class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">{{ $feed }}</p>
                    <p @class([
                        'mt-1 text-sm font-semibold',
                        'text-[var(--vg-edge)]' => ($state['stale'] ?? true) === false,
                        'text-[var(--vg-loss)]' => ($state['stale'] ?? true) === true,
                    ])>
                        {{ ($state['stale'] ?? true) ? 'Stale' : 'Current' }}
                    </p>
                    <p class="mt-0.5 text-[11px] text-[var(--vg-slate)]">
                        {{ $state['last_success_at'] ?? 'never synced' }}
                    </p>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Feature flags --}}
    <section class="panel mt-5 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Feature flags</h2>
        <p class="mt-1 text-xs text-[var(--vg-slate)]">
            Every flag arrives with a sentence saying what it does, because a flag nobody can
            explain is a flag nobody dares turn off.
        </p>

        <ul class="mt-4 divide-y divide-[var(--vg-line)]">
            @foreach ($flags as $flag)
                <li class="py-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-[family-name:var(--font-mono)] text-sm">
                                {{ $flag['key'] }}
                                @if ($flag['overridden'])
                                    <span class="badge badge-pass ml-1">overridden</span>
                                @endif
                            </p>
                            <p class="mt-0.5 text-xs text-[var(--vg-slate)]">{{ $flag['description'] }}</p>
                            @if ($flag['updated_by'])
                                <p class="mt-0.5 text-[11px] text-[var(--vg-slate)]">
                                    Last changed by {{ $flag['updated_by'] }}
                                    {{ $flag['updated_at']?->diffForHumans() }}
                                </p>
                            @endif
                        </div>

                        <span @class([
                            'badge shrink-0',
                            'badge-edge' => $flag['enabled'],
                            'badge-pass' => ! $flag['enabled'],
                        ])>{{ $flag['enabled'] ? 'On' : 'Off' }}</span>
                    </div>

                    <x-admin.reasoned-form :action="route('admin.flag')"
                                           :label="$flag['enabled'] ? 'Turn off' : 'Turn on'">
                        <input type="hidden" name="key" value="{{ $flag['key'] }}">
                        <input type="hidden" name="enabled" value="{{ $flag['enabled'] ? 0 : 1 }}">
                    </x-admin.reasoned-form>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Recent admin writes --}}
    <section class="panel mt-5 p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">Last changed</h2>
            <a class="text-xs text-[var(--vg-blue)] underline" href="{{ route('admin.audit') }}">
                The whole audit log
            </a>
        </div>

        @if ($audit->isEmpty())
            <p class="mt-2 text-sm text-[var(--vg-slate)]">Nothing has been changed by hand yet.</p>
        @else
            <ul class="mt-4 divide-y divide-[var(--vg-line)] text-sm">
                @foreach ($audit as $event)
                    <li class="py-2.5">
                        <p>
                            <span class="font-[family-name:var(--font-mono)] text-xs">{{ $event->action }}</span>
                            <span class="text-[var(--vg-slate)]">
                                by {{ $event->actor?->name ?? 'the system' }}
                                &middot; {{ $event->created_at->diffForHumans() }}
                            </span>
                        </p>
                        @if ($event->reason)
                            <p class="mt-0.5 text-xs text-[var(--vg-slate)]">{{ $event->reason }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.admin>
