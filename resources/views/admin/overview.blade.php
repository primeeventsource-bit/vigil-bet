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

    {{-- Growth and recurring revenue --}}
    <section class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {{--
            MRR and "charged" are different questions and the tiles sit
            apart for that reason. Charged is money that arrived; this
            is money expected to arrive again. A subscription cancelled
            yesterday belongs in the first and must not be in the
            second.
        --}}
        <x-stat-tile label="Monthly recurring"
                     :value="$mrrCents ? '$'.number_format($mrrCents / 100, 2) : null"
                     sample="live subscriptions at list price"
                     :tone="$mrrCents > 0 ? 'edge' : 'ink'" />

        <x-stat-tile label="New this week" :value="$newThisWeek ?: null"
                     :sample="$newThisMonth.' in the last month'" />

        <x-stat-tile label="Administrators" :value="$admins ?: null"
                     :sample="$adminsWithoutMfa > 0
                        ? $adminsWithoutMfa.' not enrolled, locked out until they are'
                        : 'all enrolled'"
                     :tone="$adminsWithoutMfa > 0 ? 'loss' : 'ink'" />

        <x-stat-tile label="Suspended accounts" :value="$suspended ?: null"
                     :sample="$suspended === 0 ? 'none' : 'locked out by an operator'"
                     :tone="$suspended > 0 ? 'loss' : 'ink'" />
    </section>

    {{-- Operational health --}}
    <section class="mt-4 grid gap-4 sm:grid-cols-2">
        <x-stat-tile label="Failed jobs" :value="$failedJobs ?: null"
                     :sample="$failedJobs === 0
                        ? 'nothing has given up'
                        : $failedJobsToday.' in the last day'"
                     :tone="$failedJobsToday > 0 ? 'loss' : 'ink'" />

        {{--
            `settled`, not `graded` - this is PublicRecord, which counts
            published picks, and OpinionRecord is the one with `graded`.
            Two summaries with different vocabularies, and the view was
            reading the wrong one's key.
        --}}
        <x-stat-tile label="Published record"
                     :value="$record['settled'] ? $record['settled'].' settled' : null"
                     :sample="$record['win_rate'] === null
                        ? 'nothing settled yet, so no rate to quote'
                        : number_format($record['win_rate'] * 100, 1).'% win rate'" />
    </section>

    {{--
        Security. Everything in it is an audit action this application
        writes, so an empty panel means nothing happened rather than
        nothing was recorded.

        Failed sign-ins are not here, and that is on purpose: audit_log
        is append-only with triggers refusing DELETE and nothing prunes
        it, so a row per wrong password would be unbounded storage an
        attacker gets to size. The lockout is recorded instead.
    --}}
    <section class="panel mt-6 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Security</h2>

        @if ($security->isEmpty())
            <p class="mt-2 text-sm text-[var(--vg-muted)]">
                No lockouts, suspensions, role changes or password resets have been recorded.
            </p>
        @else
            <ul class="mt-4 divide-y divide-[var(--vg-line)] text-sm">
                @foreach ($security as $event)
                    <li class="py-2.5">
                        <p>
                            <span class="font-[family-name:var(--font-mono)] text-xs text-[var(--vg-paper)]">{{ $event->action }}</span>
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
                        <x-chart.bar-row :label="$tier" :value="$total" :max="$byTier->max()" format="count" />
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
