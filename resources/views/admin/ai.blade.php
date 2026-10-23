<x-layouts.admin title="AI cost">
    <section class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-tile label="This month"
                     :value="'$'.number_format($spendMonth / 100000, 2)"
                     :sample="$calls === 0 ? 'no calls yet' : $calls.' calls all time'" />

        <x-stat-tile label="All time"
                     :value="'$'.number_format($spendAll / 100000, 2)"
                     sample="stored in millicents" />

        {{--
            The rejection count is the number that matters. A rising spend
            with a rising rejection rate is a prompt problem arriving as a
            bill, and it is invisible unless the two sit together.
        --}}
        <x-stat-tile label="Rejected" :value="$rejected ?: null"
                     :sample="$calls === 0
                        ? 'nothing generated'
                        : round($rejected / max($calls, 1) * 100, 1).'% of calls'"
                     :tone="$rejected > 0 ? 'loss' : 'ink'" />

        <x-stat-tile label="Cost per accepted read"
                     :value="($calls - $rejected) > 0
                        ? '$'.number_format($spendAll / 100000 / max($calls - $rejected, 1), 4)
                        : null"
                     sample="including the rejected attempts" />
    </section>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <section class="panel p-5">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">By model</h2>

            @if ($byModel->isEmpty())
                <p class="mt-2 text-sm text-[var(--vg-slate)]">Nothing generated yet.</p>
            @else
                <ul class="mt-4 divide-y divide-[var(--vg-line)] text-sm">
                    @foreach ($byModel as $row)
                        <li class="flex items-baseline justify-between gap-3 py-2.5">
                            <span class="font-[family-name:var(--font-mono)] text-xs">
                                {{ $row->model }}
                                <span class="block text-[var(--vg-slate)]" data-numeric>
                                    {{ $row->calls }} calls, {{ $row->rejected }} rejected
                                </span>
                            </span>
                            <span class="font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                ${{ number_format($row->cost / 100000, 2) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="panel p-5">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">By purpose</h2>

            @if ($byPurpose->isEmpty())
                <p class="mt-2 text-sm text-[var(--vg-slate)]">Nothing generated yet.</p>
            @else
                <ul class="mt-4 divide-y divide-[var(--vg-line)] text-sm">
                    @foreach ($byPurpose as $row)
                        <li class="flex items-baseline justify-between gap-3 py-2.5">
                            <span class="font-[family-name:var(--font-mono)] text-xs">
                                {{ $row->purpose }}
                                <span class="block text-[var(--vg-slate)]" data-numeric>{{ $row->calls }} calls</span>
                            </span>
                            <span class="font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                                ${{ number_format($row->cost / 100000, 2) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <section class="panel mt-5 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">Why calls were refused</h2>
        <p class="mt-1 text-xs text-[var(--vg-slate)]">
            Every rejected generation, with the validator's reason. An unsupported number means
            the model invented a figure the fact sheet did not contain — and it was paid for
            either way.
        </p>

        @if ($recent->isEmpty())
            <p class="mt-3 text-sm text-[var(--vg-slate)]">Nothing has been refused.</p>
        @else
            <ul class="mt-4 divide-y divide-[var(--vg-line)] text-sm">
                @foreach ($recent as $usage)
                    <li class="flex items-baseline justify-between gap-3 py-2.5">
                        <span class="min-w-0">
                            <span class="font-[family-name:var(--font-mono)] text-xs">{{ $usage->outcome }}</span>
                            <span class="block text-xs text-[var(--vg-slate)]">
                                {{ $usage->purpose }} &middot; {{ $usage->model }}
                                &middot; {{ $usage->created_at->diffForHumans() }}
                            </span>
                        </span>
                        <span @class([
                            'badge shrink-0',
                            'badge-edge' => $usage->accepted,
                            'badge-pass' => ! $usage->accepted,
                        ])>{{ $usage->accepted ? 'Accepted' : 'Rejected' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.admin>
