<x-layouts.app title="Today">
    <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Today</h1>

    <x-data-delayed :notice="$delayed" class="mt-4" />

    @if ($picks->isNotEmpty())
        <ul class="mt-6 space-y-3">
            @foreach ($picks as $pick)
                <li class="panel p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="badge badge-edge">Pick &middot; proven market</p>
                            <p class="mt-2 font-semibold">{{ $pick->selection }}</p>
                            <p class="mt-1 text-sm text-[var(--vg-slate)]">{{ $pick->reason }}</p>
                        </div>
                        <p class="shrink-0 text-right" data-numeric>
                            {{ $pick->best_price_american > 0 ? '+' : '' }}{{ $pick->best_price_american }}
                            <span class="block text-xs text-[var(--vg-slate)]">{{ $pick->best_book_title }}</span>
                        </p>
                    </div>
                </li>
            @endforeach
        </ul>
    @else
        {{--
            Not an empty state. No market has passed the verdict gate, so
            there is nothing to sell, and saying so plainly is the product
            working rather than failing.
        --}}
        <div class="panel mt-6 p-6">
            <p class="badge badge-pass">No predictions are currently available</p>

            <p class="mt-3 text-[var(--vg-paper)]">
                No market has beaten the closing line by enough, often enough, to be worth
                staking. Here is what we are watching instead.
            </p>

            @if ($nearMisses->isNotEmpty())
                <ul class="mt-5 space-y-3">
                    @foreach ($nearMisses as $miss)
                        <li class="rounded-md border border-[var(--vg-line)] p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    {{-- Labelled a pass, every time it is shown. --}}
                                    <p class="badge badge-pass">Pass</p>
                                    <p class="mt-2 font-medium">{{ $miss->selection }}</p>
                                    <p class="mt-1 text-sm text-[var(--vg-slate)]">{{ $miss->reason }}</p>
                                </div>
                                @if ($miss->best_price_american)
                                    <p class="shrink-0 text-right text-sm text-[var(--vg-slate)]" data-numeric>
                                        {{ $miss->best_price_american > 0 ? '+' : '' }}{{ $miss->best_price_american }}
                                    </p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-4 text-sm text-[var(--vg-slate)]">
                    Nothing has been scanned yet either.
                </p>
            @endif
        </div>
    @endif

    <p class="mt-6 text-xs text-[var(--vg-slate)]">
        {{ config('vigil.responsible_gambling_line') }}
    </p>
</x-layouts.app>
