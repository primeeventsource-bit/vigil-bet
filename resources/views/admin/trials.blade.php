@php
    use App\Support\Clock;
@endphp

<x-layouts.admin title="Free trials">
    <p class="mt-3 max-w-3xl text-sm text-[var(--vg-slate)]">
        How many people started a {{ config('plans.trial_hours') }}-hour trial, and how many
        of them became customers.
    </p>

    {{-- The funnel, in the order it actually happens. --}}
    <section class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-tile label="Verified, never started"
                     :value="$summary['never_started'] ?: null"
                     sample="confirmed an address and stopped" />

        <x-stat-tile label="Trials started"
                     :value="$summary['activated'] ?: null"
                     :sample="$summary['running'].' running now'" />

        <x-stat-tile label="Converted"
                     :value="$summary['converted'] ?: null"
                     :tone="$summary['converted'] > 0 ? 'edge' : 'ink'"
                     :sample="$summary['ended'].' trials have ended'" />

        {{--
            Null, never zero. "0%" out of no trials is a figure somebody
            will act on and it measures nothing - the same rule the
            public record and the shadow record already follow.
        --}}
        <x-stat-tile label="Conversion rate"
                     :value="$summary['conversion_rate'] === null
                        ? null
                        : number_format($summary['conversion_rate'] * 100, 1).'%'"
                     :sample="$summary['activated'] === 0
                        ? 'no trials yet, so no rate'
                        : 'n = '.$summary['activated']" />
    </section>

    <section class="mt-4 grid gap-4 sm:grid-cols-2">
        <div class="panel p-5">
            <h2 class="text-sm font-bold">Which plan they chose</h2>
            <p class="mt-1 text-xs text-[var(--vg-muted)]">
                Current subscriptions held by people who took a trial. Somebody who subscribed
                and then cancelled is not counted &mdash; otherwise this only ever goes up.
            </p>

            <ul class="mt-4 space-y-2">
                @foreach ($summary['by_tier'] as $tier => $count)
                    <li class="flex items-baseline justify-between text-sm">
                        <span class="font-[family-name:var(--font-mono)] text-xs uppercase text-[var(--vg-slate)]">
                            {{ $tier }}
                        </span>
                        <span class="font-[family-name:var(--font-mono)] font-bold">{{ $count }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="panel p-5">
            <h2 class="text-sm font-bold">How long it takes</h2>
            <p class="mt-1 text-xs text-[var(--vg-muted)]">
                Median rather than mean. On a handful of conversions a mean is mostly whoever
                subscribed three months later.
            </p>

            <p class="mt-4 font-[family-name:var(--font-mono)] text-2xl font-bold">
                {{ $summary['median_hours_to_convert'] === null
                    ? '—'
                    : $summary['median_hours_to_convert'].'h' }}
            </p>
            <p class="mt-1 text-xs text-[var(--vg-muted)]">
                {{ $summary['median_hours_to_convert'] === null
                    ? 'too few conversions to quote a median'
                    : 'from starting the trial to subscribing' }}
            </p>

            @if ($summary['operator_granted'] > 0)
                <p class="mt-4 border-t border-[var(--vg-line)] pt-3 text-xs text-[var(--vg-slate)]">
                    {{ $summary['operator_granted'] }} trial(s) granted by an operator.
                </p>
            @endif
        </div>
    </section>

    {{-- Running --}}
    <section class="panel mt-6 overflow-hidden">
        <h2 class="px-5 pt-5 font-[family-name:var(--font-display)] font-semibold">Running now</h2>

        @if ($running->isEmpty())
            <p class="px-5 py-6 text-sm text-[var(--vg-muted)]">No trial is running.</p>
        @else
            <div class="mt-3 w-full overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <caption class="sr-only">Trials currently running</caption>
                    <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr class="border-b border-[var(--vg-line)]">
                            <th scope="col" class="px-5 py-2.5 font-medium">Account</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Started</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Ends</th>
                            <th scope="col" class="px-5 py-2.5 text-right font-medium">Left</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($running as $person)
                            <tr class="border-b border-[var(--vg-line)] last:border-0">
                                <td class="px-5 py-3">
                                    <a class="underline hover:text-[var(--vg-paper)]"
                                       href="{{ route('admin.user', $person) }}">{{ $person->name }}</a>
                                    <span class="block text-xs text-[var(--vg-slate)]">{{ $person->email }}</span>
                                </td>
                                <td class="px-3 py-3 text-xs text-[var(--vg-slate)]">
                                    {{ Clock::format($person->trial_started_at, 'j M, H:i') }}
                                </td>
                                <td class="px-3 py-3 text-xs text-[var(--vg-slate)]">
                                    {{ Clock::format($person->trial_expires_at, 'j M, H:i') }}
                                </td>
                                <td class="px-5 py-3 text-right font-[family-name:var(--font-mono)] text-xs">
                                    {{ $person->trial_expires_at->diffForHumans(null, true) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Ended --}}
    <section class="panel mt-6 overflow-hidden">
        <h2 class="px-5 pt-5 font-[family-name:var(--font-display)] font-semibold">Ended</h2>

        @if ($ended->isEmpty())
            <p class="px-5 py-6 text-sm text-[var(--vg-muted)]">No trial has ended yet.</p>
        @else
            <div class="mt-3 w-full overflow-x-auto">
                <table class="w-full min-w-[820px] text-left text-sm">
                    <caption class="sr-only">Trials that have ended</caption>
                    <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr class="border-b border-[var(--vg-line)]">
                            <th scope="col" class="px-5 py-2.5 font-medium">Account</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Ended</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Now on</th>
                            <th scope="col" class="px-5 py-2.5 font-medium">Grant more</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ended as $person)
                            <tr class="border-b border-[var(--vg-line)] align-top last:border-0">
                                <td class="px-5 py-3">
                                    <a class="underline hover:text-[var(--vg-paper)]"
                                       href="{{ route('admin.user', $person) }}">{{ $person->name }}</a>
                                    <span class="block text-xs text-[var(--vg-slate)]">{{ $person->email }}</span>
                                </td>
                                <td class="px-3 py-3 text-xs text-[var(--vg-slate)]">
                                    {{ Clock::format($person->trial_expires_at, 'j M, H:i') }}
                                </td>
                                <td class="px-3 py-3 text-xs">
                                    @if ($person->subscription && ($person->subscription->effectiveTier() !== 'free'))
                                        {{-- The conversion, said in a word. --}}
                                        <span class="badge badge-edge">{{ $person->subscription->effectiveTier() }}</span>
                                    @else
                                        <span class="text-[var(--vg-muted)]">nothing</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    @can('update', $person)
                                        <x-admin.reasoned-form :action="route('admin.trials.grant', $person)"
                                                               label="Grant">
                                            <label class="block text-xs">
                                                <span class="text-[var(--vg-slate)]">Hours</span>
                                                <input name="hours" type="number" min="1" max="720"
                                                       value="{{ config('plans.trial_hours') }}" required
                                                       class="mt-1 w-20 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                                            </label>
                                        </x-admin.reasoned-form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <p class="px-5 py-4 text-[11px] text-[var(--vg-muted)]">
            Granting a trial does not remove the original redemption &mdash; that record is
            append-only, so the history stays true and the exception stays visible as one.
        </p>
    </section>
</x-layouts.admin>
