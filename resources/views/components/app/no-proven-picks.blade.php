@props(['nearMisses'])

{{--
    Not an empty state. No market has passed the verdict gate, so there is
    nothing to sell, and saying so is the product working rather than
    failing. The near misses are analysis and are labelled a pass every
    time they are shown.
--}}
<div class="p-6">
    <p class="badge badge-pass">No proven picks today</p>

    <p class="mt-3 text-sm text-[var(--vg-paper)]">
        No market has beaten the closing line by enough, often enough, to be worth staking.
        Here is what we are watching instead.
    </p>

    @if ($nearMisses->isNotEmpty())
        <ul class="mt-4 space-y-2">
            @foreach ($nearMisses as $miss)
                <li class="flex items-start justify-between gap-4 rounded-md border border-[var(--vg-line)] px-4 py-3">
                    <div class="min-w-0">
                        <span class="badge badge-pass">Pass</span>
                        <p class="mt-1.5 truncate text-sm font-medium">{{ $miss->selection }}</p>
                        <p class="mt-0.5 text-xs text-[var(--vg-slate)]">{{ $miss->reason }}</p>
                    </div>
                    @if ($miss->best_price_american)
                        <span class="shrink-0 font-[family-name:var(--font-mono)] text-sm tabular-nums text-[var(--vg-slate)]" data-numeric>
                            {{ $miss->best_price_american > 0 ? '+' : '' }}{{ $miss->best_price_american }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <p class="mt-4 rounded-lg border border-dashed border-[var(--vg-line)] px-4 py-8 text-center text-xs text-[var(--vg-slate)]">
            Nothing has been scanned yet.
        </p>
    @endif
</div>
