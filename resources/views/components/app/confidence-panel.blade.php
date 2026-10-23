@props(['sports', 'average'])

<div class="panel min-w-0 p-5">
    <h2 class="font-[family-name:var(--font-display)] font-semibold">Model Confidence</h2>

    <div class="mt-4 flex flex-col items-center gap-5 sm:flex-row">
        <x-chart.gauge :value="$average" label="Avg confidence" :size="132" />

        <div class="w-full min-w-0 flex-1 space-y-2.5">
            @forelse ($sports as $sport)
                <div>
                    <x-chart.bar-row :label="$sport['sport']" :value="$sport['value']" />

                    @if ($sport['verdict'] !== 'proven')
                        {{--
                            Said on every row that is not proven. A
                            confidence bar beside a market that cannot
                            produce a pick would otherwise read as one
                            that can.
                        --}}
                        <p class="pl-14 text-[10px] uppercase tracking-wide text-[var(--vg-slate)]">
                            Not yet proven
                        </p>
                    @endif
                </div>
            @empty
                <p class="rounded-lg border border-dashed border-[var(--vg-line)] px-4 py-6 text-center text-xs text-[var(--vg-slate)]">
                    Nothing scanned yet.
                </p>
            @endforelse
        </div>
    </div>
</div>
