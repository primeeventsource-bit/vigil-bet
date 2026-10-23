@props(['board'])

<section class="mt-8">
    <h2 class="font-[family-name:var(--font-display)] text-lg font-semibold">What the board is made of</h2>
    <p class="mt-1 text-sm text-[var(--vg-slate)]">
        Every candidate the engine evaluated, and the reason each was declined. Nothing evaluated
        is hidden, which is what makes the count of picks meaningful.
    </p>

    <div class="panel mt-4 p-5">
        <div class="flex flex-wrap items-baseline gap-x-8 gap-y-2">
            <p>
                <span class="font-[family-name:var(--font-mono)] text-2xl font-bold tabular-nums" data-numeric>
                    {{ number_format($board['total']) }}
                </span>
                <span class="ml-1 text-sm text-[var(--vg-slate)]">evaluated</span>
            </p>

            <p>
                <span @class([
                    'font-[family-name:var(--font-mono)] text-2xl font-bold tabular-nums',
                    'text-[var(--vg-edge)]' => $board['picks'] > 0,
                ]) data-numeric>{{ number_format($board['picks']) }}</span>
                <span class="ml-1 text-sm text-[var(--vg-slate)]">sellable picks</span>
            </p>
        </div>

        @if ($board['pass_reasons']->isNotEmpty())
            <h3 class="mt-5 text-sm font-semibold">Why each was passed</h3>

            <ul class="mt-3 space-y-2">
                @foreach ($board['pass_reasons'] as $reason => $count)
                    @php($share = $board['total'] > 0 ? $count / $board['total'] : 0)
                    <li class="flex items-center gap-3 text-sm">
                        <span class="w-44 shrink-0 truncate text-[var(--vg-slate)]">
                            {{ $reason ? str_replace('_', ' ', (string) $reason) : 'no reason recorded' }}
                        </span>

                        <span class="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-[var(--vg-line)]">
                            <span class="block h-full rounded-full bg-[var(--vg-slate)]"
                                  style="width: {{ round($share * 100, 1) }}%"></span>
                        </span>

                        <span class="w-24 shrink-0 text-right font-[family-name:var(--font-mono)] text-xs tabular-nums" data-numeric>
                            {{ number_format($count) }} · {{ round($share * 100) }}%
                        </span>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-4 rounded-md border border-dashed border-[var(--vg-line)] px-3 py-5 text-center text-xs text-[var(--vg-slate)]">
                Nothing evaluated yet.
            </p>
        @endif
    </div>
</section>
